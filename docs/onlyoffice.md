# Chỉnh sửa Word/Excel với ONLYOFFICE

Hỗ trợ xem và chỉnh sửa `.docx`, `.xlsx` trực tiếp trên trình duyệt. Các file `.doc`, `.xls` cũ cần được chuyển sang DOCX/XLSX trước khi tải lên.

## Chạy bằng Docker

1. Thêm vào `.env` (secret phải có ít nhất 32 ký tự ngẫu nhiên):

   ```dotenv
   ONLYOFFICE_JWT_SECRET=<random-secret-at-least-32-characters>
   ONLYOFFICE_PUBLIC_URL=/onlyoffice
   ONLYOFFICE_INTERNAL_URL=http://onlyoffice
   ONLYOFFICE_STORAGE_URL=http://webserver
   ```

   Laravel và Docker Compose đọc cùng secret từ `.env`. Không đưa secret vào Git hoặc JavaScript. Có thể tạo secret bằng `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.

2. Khởi động dịch vụ và cập nhật database:

   ```sh
   docker compose up -d --build
   docker compose exec app php artisan migrate --force
   docker compose exec app php artisan config:clear
   ```

3. Nếu đã có volume `build_assets` từ lần chạy trước, build frontend và đồng bộ nội dung `public/build` mới vào volume đó. Rebuild image không tự thay thế dữ liệu trong named volume đã tồn tại:

   ```sh
   npm run build
   docker compose cp ./public/build/. app:/var/www/public/build/
   ```

4. Chờ `docker compose ps` báo ONLYOFFICE healthy. Mở `http://localhost:8111/onlyoffice/healthcheck` (kết quả `true`). Vào ứng dụng, đăng nhập, upload DOCX/XLSX rồi chọn **Chỉnh sửa**. Nhấn Ctrl+S và chờ editor báo đã lưu trước khi đóng; tải lại file để kiểm tra nội dung.

Document Server là dịch vụ riêng, cần tối thiểu khoảng 4 GB RAM theo [yêu cầu hệ thống](https://helpcenter.onlyoffice.com/docs/installation/docs-community-sys-reqs-docker.aspx). Bản tích hợp dùng image Community 9.3; cần đối chiếu [giấy phép và các phiên bản](https://www.onlyoffice.com/docs.aspx) khi triển khai cho tổ chức.

## Quyền và cách lưu

- Khi upload sau đăng nhập, có thể chọn nhiều **Người được phép chỉnh sửa**, tìm theo tên hoặc mã nhân viên. Bỏ trống thì chỉ chủ sở hữu được sửa. Chủ sở hữu có thể mở **Quyền truy cập** ở danh sách để thêm/bỏ người được chỉnh sửa sau khi upload, bao gồm file cũ.
- Ô chọn có lựa chọn **Tất cả người dùng**: mọi tài khoản đã đăng nhập, kể cả tài khoản tạo sau này, được xem/tải/sửa file. Lựa chọn này thay thế danh sách cá nhân; chọn một người cụ thể để chuyển về cấp quyền từng người. Khách chưa đăng nhập không được cấp quyền qua lựa chọn này. Khi triển khai cần chạy migration `2026_10_19_000002_add_all_users_can_edit_to_uploads` bằng `php artisan migrate --force`; quyền cũ giữ nguyên mặc định.
- Với file bảng tin chung, **Quyền truy cập** còn có tùy chọn **Chặn tải file đối với người không được cấp quyền**. Khi thay đổi, file được chuyển giữa storage public/private mà không đổi nội dung. Với file cá nhân, chỉ chủ sở hữu và người được chọn được xem/tải/sửa, nên hộp thoại chỉ cần danh sách người chỉnh sửa. Tính năng dùng các bảng hiện có, không cần migration mới.
- Khi bỏ người khỏi danh sách, quyền mở/sửa/lưu của họ và URL cấp nội dung file cá nhân đã phát hành được kiểm tra lại ở backend. Tài liệu đã mở cần tải lại trang để cập nhật nút tải/copy/in; các bản đã tải về trước đó không thể thu hồi.
- File cá nhân: chủ sở hữu và những người được chọn có thể xem/tải/sửa. File được chia sẻ xuất hiện trong **File cá nhân** của người nhận, có nhãn **Được chia sẻ bởi…**. Chỉ chủ sở hữu được xóa file.
- File bảng tin chung có tài khoản sở hữu: mọi người có thể xem, chủ sở hữu và những người được chọn được sửa. File upload ẩn danh (`user_id` rỗng và không `view_only`) cho phép bất kỳ ai truy cập chỉnh sửa, kể cả qua ngrok, đổi trình duyệt hoặc không đăng nhập. Quyền này áp dụng cả với file ẩn danh đã tồn tại, không cần upload lại.
- Nút **Xem** mở chế độ xem. Người có quyền sửa có nút **Chỉnh sửa** ở danh sách và trên thanh công cụ trang xem. Quyền sửa file ẩn danh không phụ thuộc session; mỗi phiên trình duyệt có định danh editor riêng để hỗ trợ cùng sửa tài liệu.
- `view_only` áp dụng cho những người không được cấp quyền: họ chỉ xem, không có nút tải/in/copy trong editor; đây không phải DRM. Chủ sở hữu và người được chọn vẫn có thể tải và sửa file. Người nhận phải đăng nhập đúng tài khoản để được nhận diện quyền.
- File hết hạn không thể mở, tải qua URL editor hoặc lưu callback. Callback không tạo lại file đã xóa. Một phiên mở tối đa 12 giờ và không dài hơn thời hạn file; đóng/mở lại để bắt đầu phiên mới.
- Ctrl+S kích hoạt force save (`status=6`); khi kết thúc chỉnh sửa, ONLYOFFICE gửi final save (`status=2`). Laravel tải bản đã chỉnh sửa về storage và cập nhật dung lượng của file cá nhân. Sau final save, khóa tài liệu được thay đổi để lần mở tiếp theo lấy đúng phiên bản.
- URL nội dung/callback có chữ ký, thời hạn và khóa phiên. Callback còn phải có JWT của Document Server; quyền của người chỉnh sửa được kiểm tra lại trước mỗi lần lưu. Chỉ các đường dẫn cache của ONLYOFFICE được tải về, thông qua địa chỉ nội bộ cố định; không theo redirect.
- Giới hạn bản lưu là 100 MB; có thể điều chỉnh trong `config/onlyoffice.php` cùng timeout nếu cần.

## Mạng và HTTPS

Nginx proxy `/onlyoffice/` cùng origin với ứng dụng, bao gồm WebSocket. Không cần công khai thêm port của Document Server. Khi ứng dụng ở sau proxy HTTPS/ngrok, proxy phía trước phải chuyển đúng `X-Forwarded-Proto` và hỗ trợ WebSocket.

Nếu Document Server nằm ở máy khác, đặt `ONLYOFFICE_PUBLIC_URL` thành URL HTTPS mà trình duyệt truy cập được, `ONLYOFFICE_INTERNAL_URL` thành URL Laravel truy cập được, và `ONLYOFFICE_STORAGE_URL` thành URL Laravel mà Document Server truy cập được. Giữ cùng JWT secret ở cả hai bên. `ALLOW_PRIVATE_IP_ADDRESS=true` trong Compose phục vụ mạng Docker nội bộ.

## Kiểm thử

```sh
php artisan test --filter=OnlyOfficeTest
npm run build
```

Tài liệu API: [mở file](https://api.onlyoffice.com/docs/docs-api/get-started/how-it-works/opening-file), [lưu file](https://api.onlyoffice.com/docs/docs-api/get-started/how-it-works/saving-file), [callback](https://api.onlyoffice.com/docs/docs-api/usage-api/callback-handler).
