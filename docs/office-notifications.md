# Lịch sử lưu file Office và thông báo

## Sử dụng

Trong trang xem/chỉnh sửa DOCX hoặc XLSX, chọn **Bật thông báo** và cho phép trình duyệt hiện thông báo. Sau khi người khác lưu thay đổi và Laravel lưu bản mới thành công, tab đang ẩn hoặc cửa sổ mất focus sẽ hiện thông báo hệ điều hành. Click thông báo để quay lại cửa sổ tài liệu. Thông báo cùng file được thay thế/gộp; bỏ qua lần lưu có người chỉnh sửa cuối cùng là chính người xem. Nhiều tab cùng origin dùng Web Locks/localStorage để hạn chế thông báo trùng.

Chọn **Lịch sử chỉnh sửa** để xem tối đa 50 lần lưu gần nhất. Lịch sử bắt đầu từ lúc triển khai tính năng, không tái tạo thay đổi cũ. Đây là nhật ký lần lưu, không lưu bản sao từng phiên bản, không khôi phục hay thể hiện diff từng ô/đoạn. Lần lưu có thể bao gồm thay đổi của nhiều người; tên hiển thị là người sửa cuối cùng do callback ONLYOFFICE xác định. Nếu callback thiếu thông tin thì ghi “Không xác định”, không suy đoán từ người trong URL callback. Khách có mã phiên riêng và hiển thị “Khách”.

Thông báo cần HTTPS hoặc localhost, quyền trình duyệt và quyền thông báo của hệ điều hành. HTTP qua IP LAN vẫn xem lịch sử được. Tab/browser bị đình chỉ, máy sleep hoặc chế độ không làm phiền có thể làm chậm/chặn thông báo. Đóng tab không nhận Web Push. Nội dung ONLYOFFICE vẫn đồng bộ qua kết nối riêng của editor; kênh mới chỉ thông báo lần lưu.

## Luồng xử lý

1. Callback status 2/6 được xác thực JWT/chữ ký, key, quyền, hạn dùng và quota như trước.
2. Hash bản tải về được so với file hiện tại. Có thay đổi mới ghi `file_activities` trong transaction và thay thế file. Retry cùng callback không ghi/broadcast lại. Callback đã ghi lịch sử khi bị gửi lại trễ không thay thế một bản mới hơn.
3. Sau transaction, Laravel phát `file.saved` qua Laravel Reverb đến kênh private `office.{scope}.{id}`. Event chỉ chứa ID hoạt động, không chứa nội dung file, danh tính hay URL/token. Broadcast lỗi không làm callback báo lưu thất bại; lịch sử vẫn còn để lấy bù.
4. React lấy thông tin hoạt động qua API kiểm tra lại quyền. Tab mới không thông báo toàn bộ lịch sử cũ: cursor được lấy khi trang editor được tạo. Khi subscribe/reconnect/focus/online, lấy bù sự kiện sau cursor; thêm đối chiếu mỗi 60 giây khi tab còn hoạt động. Lấy nhiều trang nếu bỏ lỡ hơn 100 sự kiện. Phiên hết hạn hoặc mất quyền thì dừng theo dõi. Quyền cũng được kiểm tra ở mỗi lần lấy thông tin, nên subscription cũ không tiết lộ tên/nội dung sau khi quyền bị thu hồi.

Private channel dùng session/CSRF của trang để xác thực, kể cả khách được phép xem. Không cần tài khoản Pusher bên ngoài: `pusher-js` chỉ là client giao thức cho Reverb tự host. Không cần Redis với một instance Reverb, không cần queue worker cho event này.

Nhật ký metadata không tính vào quota file, không lưu URL tải hoặc token callback. File bị xóa/hết hạn không truy cập lịch sử được; bản ghi audit được giữ lại trong DB.

## Triển khai Docker

Cần commit toàn bộ migration, model, event, service, controller, config, frontend, hai lockfile, compose và Nginx cùng nhau. Composer thêm `laravel/reverb`; npm thêm `pusher-js`.

```powershell
docker compose up -d --build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan optimize:clear
docker compose restart reverb
docker compose exec -T webserver nginx -t
docker compose exec -T webserver nginx -s reload
```

Nếu volume `build_assets` còn bản cũ, cần cập nhật cả `public/build` theo quy trình deploy hiện có. Docker image build đã cài Composer/npm; không cần chạy lại Composer thủ công trong container vừa rebuild. Hệ thống đang bind-mount source/vendor thì cần bảo đảm vendor trên host chứa các dependency trong composer.lock.

Reverb là service mới chạy cổng 8080 **trong mạng Docker**, không publish thêm cổng host. Browser kết nối `/office-ws/app/...` qua cùng domain/cổng của site; Nginx chuyển vào Reverb. Localhost, LAN và Cloudflare Tunnel dùng URL hiện tại, tự chọn ws/wss. Không cần thêm route Cloudflare cho socket.

Mặc định app ID/key là `file-manager`; secret được dẫn xuất từ APP_KEY (không đưa ra frontend). Có thể override REVERB_APP_SECRET, nhưng phải giống nhau ở app/reverb. Không dùng secret rỗng. REVERB_ALLOWED_ORIGINS có thể giới hạn hostname phân cách dấu phẩy, ví dụ `localhost,127.0.0.1,pms-uvi.site`; mặc định `*` cho môi trường tunnel thay đổi, vẫn bắt buộc xác thực private channel. Tắt client events để client không giả mạo thông báo lưu.

## Kiểm thử

```powershell
docker compose exec -T app php artisan test --compact --filter='OfficeActivityTest|OnlyOfficeTest'
```

Ngoài test backend cần thử hai trình duyệt/tài khoản hoặc hai phiên khách: A bật thông báo rồi chuyển tab/thu nhỏ; B sửa và Ctrl+S; A nhận thông báo sau khi file lưu thành công, bấm để quay lại. Save không đổi nội dung không tạo thêm nhật ký. Thử thêm file cá nhân, thu hồi quyền, file hết hạn và mất/kết nối lại mạng.

Tham khảo: [ONLYOFFICE callback](https://api.onlyoffice.com/docs/docs-api/usage-api/callback-handler), [Laravel Reverb](https://laravel.com/docs/reverb), [Notifications API](https://developer.mozilla.org/en-US/docs/Web/API/Notifications_API).
