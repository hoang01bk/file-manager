# Tách các trang PDF thành một file mới

## Cách dùng

Trong **File Share** hoặc **File cá nhân**, bấm **Tách PDF** ở đầu danh sách file. Có hai nguồn:

- **Chọn từ máy:** chọn/kéo thả một PDF tối đa 100 MiB. Hệ thống đọc số trang để hiển thị phần chọn trang. PDF nguồn không được đưa vào danh sách upload; chỉ bản kết quả được lưu.
- **PDF đã upload:** tìm và chọn PDF trong cùng khu vực mà bạn có quyền chỉnh sửa. Danh sách tìm kiếm lấy từ server, không chỉ giới hạn ở trang danh sách đang xem. Mỗi lượt tìm trả tối đa 50 kết quả; nhập tên để thu hẹp kết quả.

Cũng có thể mở **menu ⋯ của một PDF → Tách PDF** để chọn sẵn file đó.

Sau khi đọc file:

1. Xem **preview lớn từng trang**, xếp một cột và cuộn dọc như đọc PDF; click trực tiếp vào trang để chọn/bỏ chọn. Trang đã chọn có viền xanh và dấu tick. Có **Chọn tất cả** và **Bỏ chọn**; mỗi nhóm hiển thị tối đa 60 trang và có phân trang khi PDF dài. Ảnh được dựng ở độ phân giải cao khi cuộn đến và giải phóng khi ra xa vùng xem.
2. Hoặc nhập trang theo dạng `1,3,5-8`. Các trang được đánh số từ 1 theo vị trí thực tế trong PDF, không theo nhãn trang in trên tài liệu.
3. Đặt tên và bấm **Tạo PDF mới**. Với PDF chọn từ máy ở File Share, chọn thêm thời gian tự xóa bản mới.

Khi click chọn, các trang được sắp theo thứ tự tăng dần trong PDF gốc. Khi nhập trực tiếp, thứ tự nhập được giữ nguyên; `4,2-3` tạo các trang 4, 2, 3. Khoảng giảm dần như `4-2` cũng được hỗ trợ. Trang bị lặp chỉ được lấy một lần, giữ vị trí xuất hiện đầu tiên.

Mỗi trang có nút **xoay trái/phải 90°** và **Gốc** để khôi phục hướng ban đầu. Preview và PDF kết quả đều áp dụng góc xoay tương đối so với hướng gốc, kể cả PDF đã có thông tin xoay từ trước. Xoay trang không tự chọn trang; chỉ trang được chọn mới được đưa vào kết quả. Góc xoay giữ khi chuyển nhóm trang/chọn lại trang và được xóa khi đổi PDF nguồn. API nhận thêm `rotations` dạng map số trang gốc → 0/90/180/270; backend chuyển sang vị trí trang đầu ra trước khi dùng [qpdf --rotate](https://qpdf.readthedocs.io/en/stable/cli.html#option-rotate).

Không chọn trang nào, chọn trang 0, vượt số trang của PDF hoặc nhập biểu thức sai đều bị từ chối. Giới hạn mặc định là 5.000 trang và 100 MiB cho mỗi bản kết quả; UI hiển thị dung lượng là 100 MB, thống nhất với chức năng upload/ghép PDF.

## Quyền, lưu trữ và quota

- PDF gốc luôn được giữ nguyên; kết quả có bản ghi và đường dẫn lưu trữ mới.
- Với PDF đã upload trong File Sharing, phải đăng nhập và có quyền chỉnh sửa file gốc. Khách không thể chọn/tách file đã upload, kể cả file ẩn danh hoặc bật tất cả người dùng. Khách vẫn có thể chọn PDF từ máy để tạo bản mới. File chỉ được xem nhưng không được sửa sẽ không xuất hiện trong bộ chọn.
- Hệ thống kiểm tra lại quyền và hạn dùng khi tạo bản mới, không tin kết quả kiểm tra ở bước đọc số trang.
- Bản kết quả thuộc người tạo, tính vào quota của người đó; khách ở File Share sử dụng pool quota dành cho khách. File gốc vẫn chiếm dung lượng như trước.
- PDF đã upload: bản mới kế thừa quyền xem/tải, quyền tất cả người dùng, danh sách editor và thời điểm hết hạn. Chủ file gốc vẫn có quyền nếu người tạo bản mới là editor khác.
- PDF từ máy: bản mới sử dụng quyền mặc định của upload hiện tại. File cá nhân thuộc người tạo, không có editor bổ sung; File Share công khai để xem/tải, quyền sửa theo cơ chế chủ sở hữu/khách hiện tại. Có thể đổi quyền bằng chức năng quyền truy cập sẵn có sau khi tạo.
- File cá nhân yêu cầu đăng nhập. Bản tạo từ máy ở File cá nhân không có thời hạn tự xóa; ở File Share sử dụng thời gian đã chọn.
- Không hỗ trợ PDF mã hóa/bảo vệ mật khẩu. PDF hỏng, quá dung lượng, vượt quota hoặc đã hết hạn trả lỗi và không để lại bản ghi/file đầu ra dở dang.

## Backend

Tái sử dụng qpdf đã có cho ghép PDF; không thêm package Composer hoặc migration. Cấu hình executable và timeout nằm trong `config/pdf_merge.php`; `max_selected_pages` mặc định là 5.000.

Ảnh xem trước dùng package npm `pdfjs-dist` (PDF.js), dựng canvas trong trình duyệt. Worker, font, CMap và WASM được đóng gói cùng bản build, không phụ thuộc CDN bên ngoài. PDF từ máy được đọc trực tiếp để dựng ảnh; PDF đã upload tải qua API kiểm tra quyền sửa, hạn dùng và dung lượng. Server không lưu thumbnail. Khi đóng modal/đổi file, request và worker được hủy để giải phóng tài nguyên. Nếu ảnh lỗi, vẫn có thể chọn số trang hoặc nhập khoảng trang.

| API | Mục đích |
| --- | --- |
| `GET /pdf/{scope}/sources?q=...` | Tìm các PDF còn hạn mà người dùng có quyền thao tác |
| `GET /pdf/{scope}/{id}/info` | Kiểm tra file đã upload và đọc số trang |
| `GET /pdf/{scope}/{id}/preview` | Trả PDF để dựng ảnh, kiểm tra quyền như khi tách; response private, no-store |
| `POST /pdf/{scope}/inspect` | Đọc số trang của PDF gửi từ máy, không lưu bản gốc |
| `POST /pdf/{scope}/extract` | Tạo bản kết quả; body gồm `name`, `pages`, và đúng một trong `file` hoặc `source_id`; thêm `ttl_minutes` khi tạo từ máy ở scope shared |

`scope` là `shared` hoặc `personal`. Các API dành cho giao diện, không có command người dùng phải chạy.

PDF chọn từ máy được gửi ở bước đọc số trang, sau đó gửi lại khi tạo kết quả; server không giữ một bản upload tạm lâu dài giữa hai request. Browser giữ đối tượng File để gửi lại, không bắt người dùng chọn file hai lần.

qpdf đọc số trang bằng `--show-npages`, tạo bản mới bằng `--empty --pages ...`. Biểu thức trang được phân tích thành các số trang hợp lệ trước khi truyền bằng mảng argument, không ghép shell command. Tiến trình có timeout; file kết quả phải đáp ứng giới hạn dung lượng trước khi ghi vào storage và DB.

Tách trang không đảm bảo giữ toàn bộ bookmark, form hoặc chữ ký số của tài liệu gốc trên bản kết quả. Tham khảo [qpdf Page Selection](https://qpdf.readthedocs.io/en/stable/cli.html#page-selection).

## Kiểm thử và triển khai

```powershell
docker compose exec -T app php artisan test --compact --filter=PdfExtractTest
```

Tests dùng PDF nhiều trang thật nhỏ, kiểm tra trang/thứ tự qua JSON của qpdf, hai loại nguồn, quyền truy cập, quota và dọn file khi transaction lỗi.

Frontend cần `npm ci`, build và cập nhật volume asset theo quy trình project (Docker build tự cài dependency). Copy toàn bộ `public/build`, gồm cả thư mục `pdfjs`. Nếu app đã có qpdf từ chức năng ghép PDF thì không cần cài thêm gói hệ thống cho chức năng này. Khi triển khai, commit đầy đủ controller, request, service, component, config, package.json, package-lock.json, vite.config.js và route mới cùng nhau để tránh lỗi thiếu file.
