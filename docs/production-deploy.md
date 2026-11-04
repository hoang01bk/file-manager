# Deploy từ image, không bind-mount source/vendor

`docker-compose.prod.yml` là stack production độc lập, khai báo đủ các service, network và volume. Không kế thừa `docker-compose.yml`, không dùng YAML anchors hay `!override`. File Compose mặc định vẫn dành cho development. Khi deploy chỉ dùng `-f docker-compose.prod.yml`.

## Những gì thay đổi

- App/Reverb/worker/scheduler chạy code và Composer dependencies trong image PHP. Nginx dùng target `web`, chứa public và asset từ cùng bản build.
- Không mount toàn bộ project, `vendor`, `node_modules` hoặc `public/build`. Asset mới đi cùng image; không cần `docker compose cp`, npm/composer trên host hay xóa volume asset khi cập nhật.
- `.env` truyền qua `env_file` lúc tạo container, không được copy vào image. App chạy APP_ENV=production, APP_DEBUG=false. Giữ nguyên APP_KEY hiện tại để session/dữ liệu mã hóa tiếp tục sử dụng được.
- `storage/app` và `storage/logs` vẫn mount từ project trên host. File upload public/private được giữ ở đúng vị trí cũ, không cần di chuyển. File session cũng giữ nguyên `storage/framework/sessions`. DB và ONLYOFFICE dùng các volume hiện có với cùng Compose project name.
- View/cache tạm của app nằm trong volume Docker `app_framework`. Mỗi service có config/route cache trong filesystem riêng của container. Entry point tạo cache lúc khởi động, sau khi có đủ biến môi trường; app tạo thêm Blade view cache.
- OPcache không kiểm tra timestamp code trong chế độ deploy. Rebuild/recreate container mới áp dụng thay đổi code. Sửa file PHP trên host sẽ không thay đổi ứng dụng đang chạy.
- Build bỏ qua vendor/node_modules/cache/upload/log và secret trên host. Composer cài từ lockfile, dùng authoritative class map; npm dùng `npm ci`.

## Lần đầu và các lần cập nhật

Chạy trong thư mục project chứa `.env` hiện tại. Dùng cùng project name/`-p` như trước để giữ nguyên volume DB và ONLYOFFICE.

```powershell
git pull
docker compose -f docker-compose.prod.yml build app webserver

# Chạy bằng image mới; không mount source. Chỉ cần khi bản cập nhật có migration.
docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan migrate --force

docker compose -f docker-compose.prod.yml up -d --no-build
```

Không cần `down`, xóa volume, copy asset hay chạy Composer lại. Giữ nguyên các thư mục storage và volume dữ liệu. Image app và web phải được build/cập nhật cùng nhau.

Nếu cần queue worker và scheduler:

```powershell
docker compose -f docker-compose.prod.yml --profile background up -d --no-build
```

Hai service này dùng cùng image app và dữ liệu upload. Scheduler chạy tác vụ dọn file hết hạn theo cấu hình hiện có; profile không được bật tự động khi chỉ chạy lệnh deploy thông thường.

Cache được tạo lại tự động khi container khởi động. Sau khi đổi `.env`, cần recreate các service PHP đang dùng:

```powershell
docker compose -f docker-compose.prod.yml up -d --no-build --force-recreate app reverb
# Nếu đang bật background, thêm --profile background và tên worker scheduler.
```

Không chạy `optimize:clear` như bước cuối của deploy này vì nó xóa cache vừa tạo. Nếu đã clear để chẩn đoán, restart service PHP để entry point tạo lại cache. Reverb đi qua `/office-ws/` trên Nginx, giữ nguyên domain/Cloudflare Tunnel và cổng site 8111.

## Kiểm tra

```powershell
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml exec -T app php artisan about --only=environment,cache
docker compose -f docker-compose.prod.yml exec -T webserver nginx -t
curl.exe -s -o NUL -w "HTTP=%{http_code} TTFB=%{time_starttransfer}\n" http://localhost:8111/
```

Config/Routes/Views phải hiện CACHED, Debug OFF. Kiểm tra đăng nhập, upload/download, ONLYOFFICE Save và thông báo. Nếu app không healthy, xem `logs --tail=50 app` trước; entry point dừng khi không tạo được cache để không chạy với cấu hình thiếu.

## Quay lại development

```powershell
docker compose up -d --build app webserver reverb
```

Lệnh mặc định dùng lại bind-mount source/vendor và volume asset của development. Nếu asset dev cũ, build/copy lại theo quy trình dev. Dữ liệu DB/upload dùng chung nên không cần restore hay xóa volume. Việc quay lại image cũ không tự rollback migration DB.

Tham khảo: [Laravel configuration cache](https://laravel.com/docs/configuration#configuration-caching), [Docker Compose](https://docs.docker.com/reference/cli/docker/compose/).
