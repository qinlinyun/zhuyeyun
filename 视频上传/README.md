# 远程上传后端

这是主站「上传管理」配套的远程上传后端，负责接收用户上传的 mp4、审核通过后调用 FFmpeg 切片，并把视频记录同步回主站。

## 环境要求

- PHP 8.0+，需要启用 `curl` 更稳定地执行长时间审核/同步请求；未启用时会回退到 `file_get_contents`。
- 服务器需要安装 FFmpeg / FFprobe，并在 `config.php` 中配置正确的 `FFMPEG_PATH`、`FFPROBE_PATH`。
- `storage` 目录需要 PHP 进程可写，用于保存待审核 mp4、m3u8 切片、封面和原始文件。

## 配置

编辑 `config.php`：

- `MAIN_SITE_URL`：主站地址，例如 `https://www.example.com`
- `API_TOKEN`：主站「上传管理 -> 上传后端配置 -> API配置」生成的 Token
- `VIDEO_SYNC_SECRET`：主站「视频数据 API 同步」中的 API 密钥，和 `API_TOKEN` 不是同一个值
- `VIDEO_SYNC_PATH_PREFIX`：必须和主站 `video_sync_path_prefix` 保持一致，默认建议 `/videos/`
- `UPLOAD_DOMAIN`：主站 `upload.php` 内嵌 `embed_upload.php` 的域名根路径（例如 `https://upload.example.com/视频上传`）
- `VIDEO_DOMAIN`：视频访问域名
- `IMAGE_DOMAIN`：图片访问域名
- `M3U8_DIR`：m3u8 文件目录
- `MP4_DIR`：mp4 文件目录

主站需要开启「视频数据 API 同步」，并保证 `VIDEO_SYNC_SECRET` 与主站 API 密钥一致。后端会生成并签名 `m3u8_url`，例如 `storage/m3u8/用户ID/10位目录/index.m3u8` 与 `storage/m3u8/用户ID/10位目录/screenshot.jpg`。

也可以访问 `config_guide.php` 查看配置引导并运行开箱自检。页面会检测主站 API、上传接口连通性、PHP 上传限制、FFmpeg 与 storage 权限，并给出可读诊断结果。

## 登录

访问 `login.php`，使用主站管理员账号密码登录。后端不会保存管理员密码，会通过主站 `api/upload_admin_auth.php` 进行校验。

## 上传与审核流程

- **手机端独立页**：主站 `upload.php` 在手机浏览器会自动跳转到 `mobile_upload.php`（需配置 `UPLOAD_DOMAIN` 与 `MAIN_SITE_URL`）。主站生成 `upload_token` 后跳转，用户在该页填写名称/简介、选择视频并点击「上传并提交审核」；后端保存文件后通过 `api/upload_complete_remote.php` 登记主站审核（凭 `API_TOKEN`）。
- **分片上传**：`embed_upload.php` / `mobile_upload.php` / 后台 `upload.php` 在文件 **大于 25MB** 时自动启用；每片 **25MB**，流程为 `api/upload/init.php` → `api/upload/chunk.php` × N → `api/upload/finish.php` 合并为 mp4。内嵌上传完成后主站 `api/upload_complete.php` 登记审核。
- 不超过 25MB 仍走整文件 POST，避免小文件多一次握手。
- 兼容整文件直传：旧客户端仍可使用 `api/upload_video.php`（小文件）。
- 上传鉴权优先使用短时 `upload_token`；服务间调用仍可使用 `api_token`。
- 管理员审核通过时，主站调用 `api/review_action.php`，后端执行 FFmpeg 切片、生成封面，再回调主站 `api/video_data_sync.php` 创建视频记录。
- 管理员删除已发布视频时，后端会根据主站传回的媒体路径清理 `storage/{M3U8_DIR}` 下的 m3u8、ts 和封面文件。
- 如需保留源 mp4，请在审核通过前执行“保存原始文件”；审核通过并同步成功后，默认会删除待审核源文件。
