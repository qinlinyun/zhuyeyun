<?php
session_start();

require_once __DIR__ . '/includes/bootstrap.php';

$config = require __DIR__ . '/includes/config.php';
requireDatabaseInstalled();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . $config['login_page']);
    exit;
}

$timeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    header('Location: ' . $config['login_page']);
    exit;
}
$_SESSION['last_activity'] = time();

$uploadDirInit = ensureUploadDirectory($config['upload_dir']);
$config['upload_dir'] = $uploadDirInit['path'];
$storageInitError = $uploadDirInit['ok'] ? '' : $uploadDirInit['message'];


function generateRandomDir($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}


// 从URL下载视频文件
function downloadVideoFromUrl($url, $savePath) {
    // 验证URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [false, "无效的URL地址"];
    }
    
    // 设置超时时间
    $timeout = 300; // 5分钟
    
    // 初始化cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    
    // 执行请求
    $response = curl_exec($ch);
    
    // 检查错误
    if(curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [false, "下载失败: " . $error];
    }
    
    // 检查HTTP状态码
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        return [false, "HTTP错误: " . $httpCode];
    }
    
    // 检查内容是否为空
    if (empty($response)) {
        return [false, "下载的文件内容为空"];
    }
    
    // 保存文件
    if (file_put_contents($savePath, $response) === false) {
        return [false, "无法保存下载的文件"];
    }
    
    // 检查文件大小
    if (filesize($savePath) == 0) {
        unlink($savePath);
        return [false, "下载的文件大小为0"];
    }
    
    return [true, "下载成功"];
}

// 获取视频时长
function getVideoDuration($file, $ffprobePath) {
    $cmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$file\"";
    exec($cmd, $output, $returnCode);
    if ($returnCode !== 0) {
        return false;
    }
    return floatval($output[0]);
}

// 切片视频
function sliceVideo($inputFile, $outputDir, $ffmpegPath, $screenshotTime = null, $disguiseTsAsJson = false) {
    // 基础命令
    $cmd = "$ffmpegPath -i \"$inputFile\" -c:v copy -c:a copy -bsf:v h264_mp4toannexb -hls_time 10 -hls_list_size 0 -hls_flags independent_segments -start_number 0 ";
    
    // 指定切片文件扩展名
    $cmd .= "\"$outputDir/index.m3u8\" 2>&1";
    
    exec($cmd, $output, $returnCode);
    
    if ($returnCode !== 0) {
        return [false, implode("\n", $output)];
    }
    
    // 如果需要伪装ts为json，重命名切片文件
    if ($disguiseTsAsJson) {
        $tsFiles = glob("$outputDir/*.ts");
        foreach ($tsFiles as $tsFile) {
            $jsonFile = pathinfo($tsFile, PATHINFO_DIRNAME) . '/' . pathinfo($tsFile, PATHINFO_FILENAME) . '.cnf';
            rename($tsFile, $jsonFile);
        }
        // 更新m3u8文件中的扩展名
        $m3u8Content = file_get_contents("$outputDir/index.m3u8");
        $m3u8Content = str_replace('.ts', '.cnf', $m3u8Content);
        file_put_contents("$outputDir/index.m3u8", $m3u8Content);
    }
    
    // 截取视频图片
    if ($screenshotTime !== null) {
        $screenshotPath = "$outputDir/screenshot.jpg";
        $cmd = "$ffmpegPath -i \"$inputFile\" -ss $screenshotTime -vframes 1 -q:v 2 \"$screenshotPath\" 2>&1";
        exec($cmd, $ssOutput, $ssReturnCode);
        
        if ($ssReturnCode !== 0) {
            return [false, "生成截图失败: " . implode("\n", $ssOutput)];
        }
    }
    
    return [true, ""];
}
    

// 处理上传、URL下载和切片
$uploadError = '';
$uploadSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['form_type'] ?? '', ['domain_settings', 'api_play_settings', 'api_video_sync_settings'], true)) {
    // 获取表单参数
    $screenshotTime = isset($_POST['screenshot_time']) ? trim($_POST['screenshot_time']) : null;
    $disguiseTsAsJson = isset($_POST['disguise_ts']) ? (bool)$_POST['disguise_ts'] : false;
    
    // 标记是文件上传还是URL下载
    $isUrlDownload = isset($_POST['video_url']) && !empty(trim($_POST['video_url']));
    $videoFile = $_FILES['video'] ?? null;
    
    // 验证输入方式
    if ($storageInitError !== '') {
        $uploadError = $storageInitError;
    } elseif (!$isUrlDownload && (!$videoFile || $videoFile['error'] !== UPLOAD_ERR_OK)) {
        $uploadError = "请选择视频文件或输入视频URL";
    } else {
        $randomDir = generateRandomDir();
        $uploadPath = $config['upload_dir'] . $randomDir;
        $subDirInit = ensureUploadSubDirectory($uploadPath);
        if (!$subDirInit['ok']) {
            $uploadError = $subDirInit['message'];
        } else {
        $videoFilePath = '';
        $videoFileName = '';
        $fileExtension = 'mp4'; // 默认扩展名
        
        // 处理URL下载
        if ($isUrlDownload) {
            $videoUrl = trim($_POST['video_url']);
            
            // 从URL获取文件名和扩展名
            $urlParts = parse_url($videoUrl);
            $pathParts = pathinfo($urlParts['path'] ?? '');
            $videoFileName = $pathParts['filename'] ?? 'downloaded_video';
            $fileExtension = $pathParts['extension'] ?? 'mp4';
            
            // 设置保存路径
            $videoFilePath = "$uploadPath/original." . $fileExtension;
            
            // 下载视频
            list($downloadSuccess, $downloadMsg) = downloadVideoFromUrl($videoUrl, $videoFilePath);
            
            if (!$downloadSuccess) {
                $uploadError = "URL下载失败: $downloadMsg";
                // 清理
                if (is_dir($uploadPath)) {
                    array_map('unlink', glob("$uploadPath/*"));
                    rmdir($uploadPath);
                }
            }
        } 
        // 处理文件上传
        else {
            $allowedExtensions = ['mp4', 'mov', 'avi', 'mkv'];
            $fileExtension = strtolower(pathinfo($videoFile['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                $uploadError = "不支持的文件类型。支持的格式: " . implode(', ', $allowedExtensions);
                // 清理
                if (is_dir($uploadPath)) {
                    array_map('unlink', glob("$uploadPath/*"));
                    rmdir($uploadPath);
                }
            } else {
                // 检查文件大小
                $maxFileSize = 2 * 1024 * 1024 * 1024; // 2GB
                if ($videoFile['size'] > $maxFileSize) {
                    $uploadError = "文件过大，最大支持2GB";
                    // 清理
                    if (is_dir($uploadPath)) {
                        array_map('unlink', glob("$uploadPath/*"));
                        rmdir($uploadPath);
                    }
                } else {
                    $tempFilePath = $videoFile['tmp_name'];
                    $videoFilePath = "$uploadPath/original." . $fileExtension;
                    $videoFileName = pathinfo($videoFile['name'], PATHINFO_FILENAME);
                    
                    if (!move_uploaded_file($tempFilePath, $videoFilePath)) {
                        $uploadError = "无法移动上传的文件";
                        // 清理
                        if (is_dir($uploadPath)) {
                            array_map('unlink', glob("$uploadPath/*"));
                            rmdir($uploadPath);
                        }
                    }
                }
            }
        }
        
        // 如果前面的步骤没有错误，继续处理视频
        if (empty($uploadError) && !empty($videoFilePath) && file_exists($videoFilePath)) {
            // 获取视频时长
            $duration = getVideoDuration($videoFilePath, $config['ffprobe_path']);
            
            // 验证截图时间
            if ($screenshotTime !== null) {
                if (!is_numeric($screenshotTime) || $screenshotTime < 0 || ($duration !== false && $screenshotTime > $duration)) {
                    $screenshotTime = $duration !== false ? min(5, $duration) : 5;
                }
            } else {
                $screenshotTime = $duration !== false ? min(5, $duration) : 5;
            }
            
            // 切片视频
            list($success, $sliceError) = sliceVideo(
                $videoFilePath,
                $uploadPath,
                $config['ffmpeg_path'],
                $screenshotTime,
                $disguiseTsAsJson
            );
            
            if ($success) {
                // 删除原始视频文件（无论是上传的还是下载的）
                if (file_exists($videoFilePath)) {
                    unlink($videoFilePath);
                }
                
                $record = [
                    'id' => uniqid(),
                    'title' => $videoFileName,
                    'directory' => $randomDir,
                    'created_at' => date('Y-m-d H:i:s'),
                    'screenshot' => file_exists("$uploadPath/screenshot.jpg"),
                    'disguised' => $disguiseTsAsJson,
                    'source' => $isUrlDownload ? 'url' : 'upload',
                ];

                if (!addRecord($record)) {
                    $uploadError = '切片成功但保存记录失败，请检查数据库连接';
                } else {
                    $uploadSuccess = true;
                    require_once __DIR__ . '/includes/video_sync.php';
                    $syncCfg = getVideoSyncConfig();
                    if ($syncCfg['enabled'] && $syncCfg['auto_push']) {
                        $syncResult = pushVideoRecordToSite($record);
                        if (!$syncResult['ok']) {
                            $_SESSION['video_sync_notice'] = '切片成功，但同步到主站失败：' . ($syncResult['message'] ?? '未知错误');
                        } else {
                            $_SESSION['video_sync_notice'] = '切片成功，已同步到主站（视频 ID: ' . (int)($syncResult['video_id'] ?? 0) . '）';
                        }
                    }
                }
            } else {
                $uploadError = "切片失败: $sliceError";
                // 清理
                if (file_exists($videoFilePath)) {
                    unlink($videoFilePath);
                }
                if (is_dir($uploadPath)) {
                    array_map('unlink', glob("$uploadPath/*"));
                    rmdir($uploadPath);
                }
            }
        }
        }
    }
}

// 处理删除
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $recordId = $_GET['id'];
    $record = deleteRecordById($recordId);

    if ($record) {
        $directory = $config['upload_dir'] . $record['directory'];

        if (is_dir($directory)) {
            $files = glob("$directory/*");
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                } elseif (is_dir($file)) {
                    array_map('unlink', glob("$file/*"));
                    rmdir($file);
                }
            }
            rmdir($directory);
        }
    }

    header('Location: index.php?page=records');
    exit;
}

$domainSettings = getDomainSettings();
$domainSaveError = '';
$domainSaveSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'domain_settings') {
    $saveResult = saveDomainSettings([
        'main_domain' => $_POST['main_domain'] ?? '',
        'video_domain' => $_POST['video_domain'] ?? '',
        'image_domain' => $_POST['image_domain'] ?? '',
        'upload_domain' => $_POST['upload_domain'] ?? '',
    ]);
    if ($saveResult['success']) {
        header('Location: index.php?page=domains&saved=1');
        exit;
    }
    $domainSaveError = $saveResult['message'];
}

$apiPlayConfig = getPlayTokenConfig();
$apiPlaySaveError = '';
$apiPlaySaveSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'api_play_settings') {
    $saveResult = savePlayTokenConfig([
        'api_secret' => $_POST['api_secret'] ?? '',
        'token_ttl' => $_POST['token_ttl'] ?? 7200,
        'signed_script_path' => $_POST['signed_script_path'] ?? '/play_signed.php',
    ]);
    if ($saveResult['success']) {
        header('Location: index.php?page=api&play_saved=1');
        exit;
    }
    $apiPlaySaveError = $saveResult['message'];
}

require_once __DIR__ . '/includes/video_sync.php';
$apiVideoSyncConfig = getVideoSyncConfig();
$apiVideoSyncSaveError = '';
$apiVideoSyncSaveSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'api_video_sync_settings') {
    $saveResult = saveVideoSyncConfig([
        'enabled' => isset($_POST['video_sync_enabled']),
        'site_url' => $_POST['video_sync_site_url'] ?? '',
        'api_secret' => $_POST['video_sync_api_secret'] ?? '',
        'auto_push' => isset($_POST['video_sync_auto_push']),
        'path_prefix' => $_POST['video_sync_path_prefix'] ?? '/videos/',
    ]);
    if ($saveResult['success']) {
        header('Location: index.php?page=api&sync_saved=1');
        exit;
    }
    $apiVideoSyncSaveError = $saveResult['message'];
}

$allowedPages = ['upload', 'records', 'domains', 'api'];
$pageParam = $_POST['page'] ?? $_GET['page'] ?? 'upload';
$currentPage = in_array($pageParam, $allowedPages, true) ? $pageParam : 'upload';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $domainSaveSuccess = true;
    $domainSettings = getDomainSettings();
}
if (isset($_GET['play_saved']) && $_GET['play_saved'] === '1') {
    $apiPlaySaveSuccess = true;
    $apiPlayConfig = getPlayTokenConfig();
}
if (isset($_GET['sync_saved']) && $_GET['sync_saved'] === '1') {
    $apiVideoSyncSaveSuccess = true;
    $apiVideoSyncConfig = getVideoSyncConfig();
}
$mainDomainConfigured = isMainDomainConfigured();
$uploadFormAction = getUploadFormActionUrl();

function getUploadNavIconSvg(): string
{
    return <<<'SVG'
<svg class="w-5 h-5 block" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<path d="M244.55 809.86a238.52 238.52 0 0 1-29.73-475.18v-4.11a284.59 284.59 0 0 1 561.69-64.83 272.76 272.76 0 0 1-34.13 543.38 29.77 29.77 0 0 1 0-59.53 213.22 213.22 0 0 0 8-426.29l-24.78-0.92-3.6-24.54a225.06 225.06 0 0 0-447.72 32.73 229.48 229.48 0 0 0 1.77 28.36l4.25 33.79-35.09-0.33h-0.69c-98.69 0-179 80.29-179 179s80.29 179 179 179a29.77 29.77 0 0 1 0 59.53z" fill="currentColor"/>
<path d="M478.51 503.64m10 0l44 0q10 0 10 10l0 459.47q0 10-10 10l-44 0q-10 0-10-10l0-459.47q0-10 10-10Z" fill="currentColor"/>
<path d="M466.229615 503.513763m7.071068-7.071068l31.112698-31.112698q7.071068-7.071068 14.142136 0l162.387072 162.387072q7.071068 7.071068 0 14.142136l-31.112698 31.112698q-7.071068 7.071068-14.142136 0l-162.387072-162.387072q-7.071068-7.071068 0-14.142136Z" fill="currentColor"/>
<path d="M331.737959 638.760864m7.071068-7.071068l166.368083-166.368083q7.071068-7.071068 14.142136 0l31.112698 31.112698q7.071068 7.071068 0 14.142136l-166.368083 166.368083q-7.071068 7.071068-14.142136 0l-31.112698-31.112698q-7.071068-7.071068 0-14.142136Z" fill="currentColor"/>
</svg>
SVG;
}

function getRecordsNavIconSvg(): string
{
    return <<<'SVG'
<svg class="w-5 h-5 block" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<path d="M512 1023.998537A475.420846 475.420846 0 0 1 53.584592 421.85975a54.856251 54.856251 0 1 1 105.872565 29.073814A365.708343 365.708343 0 1 0 512 182.869348a361.502697 361.502697 0 0 0-169.688671 41.507897A54.856251 54.856251 0 1 1 291.477869 128.013097 475.420846 475.420846 0 1 1 512 1023.998537z" fill="currentColor"/>
<path d="M566.856251 297.518914h-54.856251a18.285417 18.285417 0 0 0-18.285417 18.285417v224.727777a18.285417 18.285417 0 0 1-18.285417 18.285417h-169.688671a18.285417 18.285417 0 0 0-18.285418 18.285417v54.856252a18.285417 18.285417 0 0 0 18.285418 18.285417H566.856251a18.285417 18.285417 0 0 0 18.285418-18.285417V315.804331a18.285417 18.285417 0 0 0-18.285418-18.285417zM402.287497 320.375685a54.856251 54.856251 0 0 1-19.931105-3.657083l-151.403254-59.244752a54.856251 54.856251 0 0 1-31.085209-71.130272l59.244752-151.403254a54.856251 54.856251 0 0 1 102.215482 39.862209l-40.227918 100.38694 100.38694 39.313647A54.856251 54.856251 0 0 1 402.287497 320.375685z" fill="currentColor"/>
</svg>
SVG;
}

function getDomainsNavIconSvg(): string
{
    return <<<'SVG'
<svg class="w-5 h-5 block" viewBox="0 0 1077 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<path d="M564.520755 644.096c3.072-17.408 25.6-23.552 37.888-11.264l106.496 105.472c15.36 15.36 39.936 15.36 55.296 0l54.272-54.272c15.36-15.36 15.36-39.936 0-54.272L711.976755 522.24c-13.312-12.288-7.168-34.816 10.24-37.888 62.464-12.288 129.024 6.144 178.176 55.296 53.248 53.248 70.656 129.024 50.176 197.632-3.072 11.264-1.024 23.552 7.168 31.744l108.544 107.52c15.36 15.36 15.36 39.936 0 54.272l-55.296 54.272c-15.36 15.36-39.936 15.36-55.296 0L847.144755 875.52c-8.192-8.192-20.48-10.24-31.744-7.168-67.584 19.456-143.36 3.072-196.608-50.176-47.104-47.104-64.512-112.64-54.272-174.08z m425.984 291.84c16.384 0 29.696-13.312 29.696-29.696s-13.312-29.696-29.696-29.696c-16.384 0-29.696 13.312-29.696 29.696s13.312 29.696 29.696 29.696z" fill="currentColor"/>
<path d="M977.192755 436.224l-22.528-81.92c-2.048-8.192-8.192-15.36-15.36-19.456-8.192-5.12-17.408-8.192-25.6-6.144L865.576755 340.992c-21.504 4.096-44.032-4.096-57.344-22.528l-41.984-54.272c-14.336-15.36-18.432-41.984-8.192-60.416l25.6-45.056c8.192-15.36 2.048-34.816-13.312-41.984L693.544755 71.68c-7.168-4.096-16.384-5.12-24.576-3.072-8.192 2.048-14.336 7.168-18.432 14.336l-25.6 43.008c-11.264 18.432-33.792 27.648-55.296 23.552l-67.584-9.216c-20.48-2.048-44.032-18.432-48.128-38.912l-13.312-48.128c-2.048-8.192-8.192-15.36-15.36-19.456-8.192-5.12-18.432-7.168-27.648-5.12l-82.944 20.48c-17.408 4.096-27.648 22.528-22.528 38.912l13.312 48.128c5.12 21.504-3.072 43.008-21.504 55.296l-54.272 40.96c-16.384 14.336-40.96 16.384-61.44 6.144l-44.032-25.6c-14.336-9.216-32.768-5.12-43.008 9.216L35.112755 301.056c-3.072 7.168-4.096 14.336-2.048 21.504 3.072 8.192 8.192 15.36 15.36 19.456l44.032 25.6c19.456 12.288 28.672 34.816 24.576 56.32L109.864755 491.52c-1.024 20.48-17.408 43.008-37.888 46.08l-48.128 12.288c-17.408 5.12-27.648 22.528-22.528 39.936l22.528 81.92c5.12 17.408 22.528 27.648 40.96 23.552l49.152-12.288c21.504-4.096 44.032 4.096 57.344 22.528l41.984 54.272c14.336 16.384 17.408 40.96 7.168 59.392l-25.6 43.008c-4.096 7.168-5.12 16.384-3.072 24.576 2.048 8.192 8.192 15.36 15.36 19.456l76.8 44.032c7.168 4.096 16.384 5.12 24.576 3.072 8.192-2.048 15.36-7.168 19.456-14.336l25.6-43.008c12.288-18.432 34.816-27.648 56.32-23.552l68.608 9.216c20.48 1.024 43.008 18.432 48.128 38.912l13.312 48.128c5.12 17.408 22.528 27.648 40.96 23.552l82.944-20.48c12.288-3.072 20.48-13.312 23.552-24.576-46.08-13.312-88.064-36.864-122.88-71.68-32.768-32.768-56.32-72.704-68.608-115.712h-3.072c-138.24 0-249.856-111.616-249.856-249.856 0-138.24 111.616-249.856 249.856-249.856 99.328 0 185.344 57.344 225.28 141.312 15.36-2.048 30.72-4.096 46.08-4.096 72.704 0 140.288 27.648 192.512 76.8h1.024c13.312-3.072 23.552-20.48 19.456-37.888z" fill="currentColor"/>
<path d="M484.648755 629.76c1.024-9.216 4.096-17.408 8.192-24.576-16.384 1.024-33.792-3.072-49.152-12.288-44.032-25.6-59.392-81.92-33.792-125.952 25.6-44.032 81.92-59.392 125.952-33.792 39.936 22.528 55.296 70.656 39.936 112.64 4.096 0 7.168-1.024 11.264-1.024 17.408 0 33.792 4.096 48.128 11.264-12.288-23.552-16.384-52.224-8.192-78.848 6.144-21.504 19.456-39.936 35.84-52.224-31.744-64.512-98.304-108.544-175.104-108.544C380.200755 316.416 292.136755 403.456 292.136755 512c0 105.472 82.944 190.464 187.392 194.56-1.024-25.6 0-51.2 5.12-76.8z" fill="currentColor"/>
</svg>
SVG;
}

function getApiNavIconSvg(): string
{
    return <<<'SVG'
<svg class="w-5 h-5 block" viewBox="0 0 1121 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<path d="M1121.335259 662.764396H814.255549V371.961528H1121.335259z m-226.885882-80.193827H1041.141431V452.155356H894.449377zM963.165463 290.802868h-306.991997V0h306.991997z m-226.798169-80.193828h146.604342V80.193828h-146.604342zM921.314309 1023.98747H614.247131V733.184601h307.067178z m-226.873351-80.193828h146.679524V813.378429H694.440958z" fill="currentColor"/>
<path d="M0 479.057879h865.692372v80.193828H0z" fill="currentColor"/>
<path d="M149.849686 545.631287L92.974719 489.094638 464.322263 115.529233h248.31267v80.193828H497.690414l-347.840728 349.908226zM654.331514 918.68295h-149.110399L242.63645 542.085216l65.784-45.860846L547.072269 838.489122h107.259245v80.193828z" fill="currentColor"/>
</svg>
SVG;
}

// 生成导航链接（$iconSvg 非空时显示图标，$text 作为 title/aria-label）
function generateNavLink($page, $text, $currentPage, $iconSvg = null) {
    $classes = 'inline-flex items-center justify-center text-white hover:text-gray-300 transition-custom px-2 py-1 rounded';
    if ($page === $currentPage) {
        $classes .= ' bg-white/15';
    }
    $href = '?page=' . htmlspecialchars($page);
    $label = htmlspecialchars($text);

    if ($iconSvg !== null && $iconSvg !== '') {
        return '<a href="' . $href . '" class="' . $classes . '" title="' . $label . '" aria-label="' . $label . '">' . $iconSvg . '</a>';
    }

    if ($page === $currentPage) {
        $classes .= ' font-medium';
    }

    return '<a href="' . $href . '" class="' . $classes . '">' . $label . '</a>';
}

// 格式化文件大小
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log(1024));
    
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>视频切片工具</title>
    <link rel="stylesheet" href="./css/layout.css?v=1">
    <!-- 引入Font Awesome -->
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <!-- 引入Video.js -->
    <link href="./play/cnf.css" rel="stylesheet">
    
    <style>
        @layer utilities {
            .content-auto {
                content-visibility: auto;
            }
            .transition-custom {
                transition: all 0.3s ease;
            }
            .shadow-custom {
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            }
            .hover-scale {
                transition: transform 0.2s ease;
            }
            .hover-scale:hover {
                transform: scale(1.02);
            }
            /* 视频播放器响应式样式 */
            .video-container {
                position: relative;
                padding-bottom: 56.25%; /* 16:9 宽高比 */
                height: 0;
                overflow: hidden;
            }
            .video-js {
                position: absolute;
                top: 0;
                left: 0;
                width: 100% !important;
                height: 100% !important;
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
    <!-- 顶部导航 -->
    <header class="bg-dark text-white shadow-md">
        <div class="container mx-auto px-4 py-3">
            <nav class="flex justify-between items-center">
                <div class="text-xl font-bold">
                    <i class="fa fa-video-camera mr-2"></i>视频切片工具
                </div>
                <div class="flex flex-wrap items-center gap-2 md:gap-4">
                    <?php echo generateNavLink('upload', '上传视频', $currentPage, getUploadNavIconSvg()); ?>
                    <?php echo generateNavLink('records', '切片记录', $currentPage, getRecordsNavIconSvg()); ?>
                    <?php echo generateNavLink('domains', '域名配置', $currentPage, getDomainsNavIconSvg()); ?>
                    <?php echo generateNavLink('api', 'API接口', $currentPage, getApiNavIconSvg()); ?>
                    <?php if (!$mainDomainConfigured): ?>
                        <span class="text-xs bg-amber-500 text-amber-950 px-2 py-0.5 rounded-full hidden sm:inline">未配置域名</span>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['username'])): ?>
    <div class="flex items-center space-x-4">
        <span class="text-sm">欢迎, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
        <a href="logout.php" class="text-white hover:text-gray-300 transition-custom">
            <i class="fa fa-sign-out mr-1"></i>退出
        </a>
    </div>
<?php endif; ?>
                </div>
            </nav>
        </div>
    </header>
    
    <!-- 主内容区 -->
    <main class="container mx-auto px-4 py-6">
        <?php if ($storageInitError !== ''): ?>
            <div class="bg-red-50 border-l-4 border-red-400 text-red-800 p-4 mb-6 rounded-r">
                <p class="font-bold">存储目录不可用</p>
                <p class="text-sm mt-1"><?php echo htmlspecialchars($storageInitError); ?></p>
                <p class="text-xs text-red-700 mt-2">目录路径：<code class="bg-red-100 px-1 rounded"><?php echo htmlspecialchars($config['upload_dir']); ?></code></p>
            </div>
        <?php endif; ?>
        <?php if ($currentPage === 'upload'): ?>
            <section class="bg-white rounded-xl shadow-custom p-6 mb-8">
                <h2 class="text-2xl font-bold mb-4 text-dark">
                    <i class="fa fa-upload mr-2"></i>上传视频或通过URL下载
                </h2>
                
                <?php if ($uploadError): ?>
                    <div class="bg-red-50 border-l-4 border-red-400 text-red-700 p-4 mb-4">
                        <p class="font-bold">处理失败</p>
                        <p><?php echo htmlspecialchars($uploadError); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($uploadSuccess): ?>
                    <div class="bg-green-50 border-l-4 border-green-400 text-green-700 p-4 mb-4">
                        <p class="font-bold">处理成功</p>
                        <p>视频已成功处理并切片。</p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['video_sync_notice'])): ?>
                    <div class="bg-blue-50 border-l-4 border-blue-400 text-blue-800 p-4 mb-4 text-sm">
                        <?php echo htmlspecialchars((string)$_SESSION['video_sync_notice']); ?>
                    </div>
                    <?php unset($_SESSION['video_sync_notice']); ?>
                <?php endif; ?>
                
                <form id="video-upload-form" action="<?php echo htmlspecialchars($uploadFormAction); ?>" method="post" enctype="multipart/form-data" class="space-y-4" data-upload-action="<?php echo htmlspecialchars($uploadFormAction); ?>">
                    <!-- 下载方式选择器 -->
                    <div class="mb-6">
                        <div class="flex border-b border-gray-200">
                            <button type="button" id="upload-tab" class="py-2 px-4 border-b-2 border-primary text-primary font-medium">
                                <i class="fa fa-file-video-o mr-1"></i>文件上传
                            </button>
                            <button type="button" id="url-tab" class="py-2 px-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700 font-medium">
                                <i class="fa fa-link mr-1"></i>URL下载
                            </button>
                        </div>
                    </div>
                    
                    <!-- 文件上传区域 -->
                    <div id="upload-section" class="space-y-4">
                        <div class="space-y-2">
                            <label for="video" class="block text-sm font-medium text-gray-700">选择视频文件</label>
                            <div class="mt-1 relative">
                                <!-- 上传区域 - 未选择文件时显示 -->
                                <div id="upload-area" class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:bg-gray-50 transition-custom">
                                    <div class="space-y-1 text-center">
                                        <i class="fa fa-file-video-o text-gray-400 text-4xl mb-2"></i>
                                        <div class="flex text-sm text-gray-600">
                                            <label for="video" class="relative cursor-pointer bg-white rounded-md font-medium text-primary hover:text-primary-dark focus-within:outline-none">
                                                <span>上传视频文件</span>
                                                <input id="video" name="video" type="file" class="sr-only">
                                            </label>
                                            <p class="pl-1">或拖放文件</p>
                                        </div>
                                        <p class="text-xs text-gray-500">
                                            支持 MP4, MOV, AVI, MKV (最大 2GB)
                                        </p>
                                    </div>
                                </div>
                                
                                <!-- 已选择文件显示区域 - 初始隐藏 -->
                                <div id="selected-file" class="hidden p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <i class="fa fa-file-video-o text-blue-500 text-xl mr-3"></i>
                                            <div>
                                                <p id="file-name" class="font-medium text-gray-900 truncate max-w-[200px]"></p>
                                                <p id="file-size" class="text-sm text-gray-500"></p>
                                            </div>
                                        </div>
                                        <button type="button" id="remove-file" class="text-gray-400 hover:text-red-500 transition-custom">
                                            <i class="fa fa-times text-xl"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- URL下载区域 -->
                    <div id="url-section" class="space-y-4 hidden">
                        <div class="space-y-2">
                            <label for="video_url" class="block text-sm font-medium text-gray-700">视频URL地址</label>
                            <div class="mt-1 relative">
                                <div class="flex">
                                    <input type="text" name="video_url" id="video_url" placeholder="例如: https://example.com/video.mp4" 
                                        class="flex-1 px-3 py-2 border border-gray-300 rounded-l-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary transition-custom">
                                    <button type="button" id="test-url" class="bg-gray-100 text-gray-700 px-4 py-2 border border-gray-300 rounded-r-md hover:bg-gray-200 transition-custom">
                                        <i class="fa fa-check mr-1"></i>测试
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    支持HTTP/HTTPS协议的视频文件URL，将自动下载并切片处理
                                </p>
                                <div id="url-test-result" class="mt-2 hidden"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-2">
                        <label for="screenshot_time" class="block text-sm font-medium text-gray-700">截图时间（秒）</label>
                        <input type="text" name="screenshot_time" id="screenshot_time" placeholder="可选，留空则自动截取第5秒" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary transition-custom">
                    </div>
                    
                    <!-- 功能选项区域 -->
                    <div class="space-y-4 pt-2">
                        <h3 class="text-lg font-medium text-gray-800">高级选项</h3>
                        
                        <div class="flex items-center">
                            <input id="disguise_ts" name="disguise_ts" type="checkbox" class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                            <label for="disguise_ts" class="ml-2 block text-sm text-gray-700">
                                <i class="fa fa-mask mr-1"></i>TS加密
                            </label>
                        </div>
                    </div>
                    
                    <div class="pt-4">
                        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-custom">
                            <i class="fa fa-cog mr-2"></i>处理并切片
                        </button>
                    </div>
                </form>
                
                <div id="progress-container" class="mt-6 hidden">
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div id="progress-bar" class="bg-primary h-2.5 rounded-full" style="width: 0%"></div>
                    </div>
                    <p id="progress-text" class="mt-2 text-sm text-gray-600">准备处理...</p>
                </div>
            </section>
        <?php elseif ($currentPage === 'domains'): ?>
            <section class="bg-white rounded-xl shadow-custom p-6 mb-8">
                <h2 class="text-2xl font-bold mb-2 text-dark">
                    <i class="fa fa-globe mr-2"></i>域名配置
                </h2>
                <p class="text-sm text-gray-500 mb-6">链接默认使用 <strong>HTTPS</strong>。视频/图片/上传域名留空时将自动使用总域名；上传域名用于指定接收上传的站点地址（仍保存到本机，不走远程 HTTP 上传）。</p>

                <?php if ($domainSaveError): ?>
                    <div class="bg-red-50 border-l-4 border-red-400 text-red-700 p-4 mb-4">
                        <p><?php echo htmlspecialchars($domainSaveError); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($domainSaveSuccess): ?>
                    <div class="bg-green-50 border-l-4 border-green-400 text-green-700 p-4 mb-4">
                        <p>域名配置已保存</p>
                    </div>
                <?php endif; ?>

                <form method="post" action="index.php?page=domains" class="space-y-5 max-w-2xl">
                    <input type="hidden" name="form_type" value="domain_settings">
                    <input type="hidden" name="page" value="domains">

                    <div>
                        <label for="main_domain" class="block text-sm font-medium text-gray-700 mb-1">
                            总域名 <span class="text-red-500">*</span>
                        </label>
                        <div class="flex rounded-md shadow-sm">
                            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-600 text-sm">https://</span>
                            <input type="text" name="main_domain" id="main_domain" required
                                value="<?php echo htmlspecialchars($domainSettings['main_domain']); ?>"
                                placeholder="example.com/videos"
                                class="flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-r-md focus:ring-primary focus:border-primary">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">必填。作为默认访问域名，可含路径，如 <code>cdn.example.com/hls</code></p>
                    </div>

                    <div>
                        <label for="video_domain" class="block text-sm font-medium text-gray-700 mb-1">视频域名</label>
                        <div class="flex rounded-md shadow-sm">
                            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-600 text-sm">https://</span>
                            <input type="text" name="video_domain" id="video_domain"
                                value="<?php echo htmlspecialchars($domainSettings['video_domain']); ?>"
                                placeholder="留空则使用总域名"
                                class="flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-r-md focus:ring-primary focus:border-primary">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">用于 M3U8、TS/CNF 切片等视频资源链接</p>
                    </div>

                    <div>
                        <label for="image_domain" class="block text-sm font-medium text-gray-700 mb-1">图片域名</label>
                        <div class="flex rounded-md shadow-sm">
                            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-600 text-sm">https://</span>
                            <input type="text" name="image_domain" id="image_domain"
                                value="<?php echo htmlspecialchars($domainSettings['image_domain']); ?>"
                                placeholder="留空则使用总域名"
                                class="flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-r-md focus:ring-primary focus:border-primary">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">用于截图、封面等图片资源链接</p>
                    </div>

                    <div>
                        <label for="upload_domain" class="block text-sm font-medium text-gray-700 mb-1">上传域名</label>
                        <div class="flex rounded-md shadow-sm">
                            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-600 text-sm">https://</span>
                            <input type="text" name="upload_domain" id="upload_domain"
                                value="<?php echo htmlspecialchars($domainSettings['upload_domain']); ?>"
                                placeholder="留空则使用本地目录存储"
                                class="flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-r-md focus:ring-primary focus:border-primary">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">填写后，上传表单将提交到该域名（本机存储，不经过当前访问域名）；留空则使用当前域名上传</p>
                    </div>

                    <?php if ($mainDomainConfigured): ?>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm space-y-2">
                            <p class="font-medium text-gray-700">当前生效预览</p>
                            <p class="text-gray-600 break-all">
                                <span class="text-gray-400">视频：</span>
                                <?php echo htmlspecialchars(buildVideoAssetUrl('示例目录', 'index.m3u8')); ?>
                            </p>
                            <p class="text-gray-600 break-all">
                                <span class="text-gray-400">图片：</span>
                                <?php echo htmlspecialchars(buildImageAssetUrl('示例目录')); ?>
                            </p>
                            <p class="text-gray-600 break-all">
                                <span class="text-gray-400">上传入口：</span>
                                <?php echo htmlspecialchars($uploadFormAction); ?>
                            </p>
                            <p class="text-gray-600 break-all">
                                <span class="text-gray-400">上传资源：</span>
                                <?php echo htmlspecialchars(buildUploadAssetUrl('示例目录', 'index.m3u8')); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="inline-flex items-center px-5 py-2 bg-primary text-white rounded-md hover:bg-primary/90 transition-custom">
                        <i class="fa fa-save mr-2"></i>保存配置
                    </button>
                </form>
            </section>
        <?php elseif ($currentPage === 'api'): ?>
            <section class="bg-white rounded-xl shadow-custom p-6 mb-8">
                <h2 class="text-2xl font-bold mb-2 text-dark">
                    <i class="fa fa-plug mr-2"></i>API 接口配置
                </h2>
                <p class="text-sm text-gray-500 mb-6">
                    供竹叶云主站「后端代理」调用：主站携带用户邮箱请求时效播放链接，本系统校验签名后返回带 token 的 <code class="text-xs bg-gray-100 px-1 rounded">play_signed.php</code> 地址。
                </p>

                <?php if ($apiPlaySaveSuccess): ?>
                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                        配置已保存
                    </div>
                <?php endif; ?>
                <?php if ($apiPlaySaveError): ?>
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <?php echo htmlspecialchars($apiPlaySaveError); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="index.php?page=api" class="space-y-5 max-w-2xl">
                    <input type="hidden" name="form_type" value="api_play_settings">
                    <input type="hidden" name="page" value="api">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="api_secret">API 密钥</label>
                        <input type="password" name="api_secret" id="api_secret" required minlength="16"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary"
                               value="<?php echo htmlspecialchars($apiPlayConfig['api_secret']); ?>"
                               autocomplete="new-password">
                        <p class="mt-1 text-xs text-gray-500">与主站「其它设置 → 播放器管理 → 后端代理」中的密钥保持一致（至少 16 位）。</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="token_ttl">默认链接有效期（秒）</label>
                        <input type="number" name="token_ttl" id="token_ttl" min="300" max="86400"
                               class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm"
                               value="<?php echo (int)$apiPlayConfig['token_ttl']; ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="signed_script_path">签名播放脚本路径</label>
                        <input type="text" name="signed_script_path" id="signed_script_path"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                               value="<?php echo htmlspecialchars($apiPlayConfig['signed_script_path']); ?>"
                               placeholder="/play_signed.php">
                        <p class="mt-1 text-xs text-gray-500">各 CDN 线路域名根路径下需能访问此脚本（默认 <code class="text-xs">/play_signed.php</code>，若放在子目录请填如 <code class="text-xs">/视频切片/play_signed.php</code>）。</p>
                    </div>

                    <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-900 space-y-2">
                        <p class="font-semibold">接口地址</p>
                        <p><code class="break-all"><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/api/play_token.php'); ?></code></p>
                        <p class="text-blue-800">POST JSON：<code>email</code>、<code>path</code>、<code>play_host</code>、<code>exp</code>、<code>sign</code></p>
                    </div>

                    <button type="submit" class="bg-primary text-white px-5 py-2 rounded-lg hover:opacity-90 transition-custom">
                        保存播放 API 配置
                    </button>
                </form>
            </section>

            <section class="bg-white rounded-xl shadow-custom p-6 mb-8">
                <h2 class="text-2xl font-bold mb-2 text-dark">
                    <i class="fa fa-refresh mr-2"></i>视频数据同步 API
                </h2>
                <p class="text-sm text-gray-500 mb-6">
                    切片完成后向竹叶云主站推送 <strong>m3u8 链接</strong>、<strong>视频名称</strong>、<strong>封面图片链接</strong>。
                    密钥需与主站「其它设置 → 播放器管理 → 视频数据API同步接口配置」一致。
                </p>

                <?php if ($apiVideoSyncSaveSuccess): ?>
                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                        数据同步配置已保存
                    </div>
                <?php endif; ?>
                <?php if ($apiVideoSyncSaveError): ?>
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <?php echo htmlspecialchars($apiVideoSyncSaveError); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="index.php?page=api" class="space-y-5 max-w-2xl">
                    <input type="hidden" name="form_type" value="api_video_sync_settings">
                    <input type="hidden" name="page" value="api">

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="video_sync_enabled" value="1" class="rounded border-gray-300"
                            <?php echo !empty($apiVideoSyncConfig['enabled']) ? 'checked' : ''; ?>>
                        启用向主站推送数据
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="video_sync_auto_push" value="1" class="rounded border-gray-300"
                            <?php echo !empty($apiVideoSyncConfig['auto_push']) ? 'checked' : ''; ?>>
                        切片成功后自动推送
                    </label>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="video_sync_site_url">主站地址</label>
                        <input type="url" name="video_sync_site_url" id="video_sync_site_url"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                               placeholder="https://你的主站域名"
                               value="<?php echo htmlspecialchars($apiVideoSyncConfig['site_url']); ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="video_sync_api_secret">API 密钥</label>
                        <input type="password" name="video_sync_api_secret" id="video_sync_api_secret" minlength="16"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                               value="<?php echo htmlspecialchars($apiVideoSyncConfig['api_secret']); ?>"
                               autocomplete="new-password">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="video_sync_path_prefix">m3u8 路径前缀</label>
                        <input type="text" name="video_sync_path_prefix" id="video_sync_path_prefix"
                               class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm"
                               value="<?php echo htmlspecialchars($apiVideoSyncConfig['path_prefix']); ?>">
                        <p class="mt-1 text-xs text-gray-500">推送到主站的分集路径前缀，默认 <code>/videos/</code></p>
                    </div>

                    <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-900 space-y-2">
                        <p class="font-semibold">拉取接口（主站批量同步时可调用）</p>
                        <p><code class="break-all"><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/api/video_sync_list.php'); ?></code></p>
                        <p>GET 参数：<code>exp</code>、<code>sign</code>（sign = HMAC-SHA256("list|{exp}", 密钥)）</p>
                    </div>

                    <button type="submit" class="bg-primary text-white px-5 py-2 rounded-lg hover:opacity-90 transition-custom">
                        保存数据同步配置
                    </button>
                </form>
            </section>
        <?php elseif ($currentPage === 'records'): ?>
            <section class="bg-white rounded-xl shadow-custom p-6 mb-8">
                <h2 class="text-2xl font-bold mb-4 text-dark">
                    <i class="fa fa-list mr-2"></i>切片记录
                </h2>

                <?php if (!$mainDomainConfigured): ?>
                    <div class="bg-amber-50 border-l-4 border-amber-400 text-amber-800 p-4 mb-4">
                        <p class="font-bold">尚未配置总域名</p>
                        <p class="text-sm mt-1">请先在 <a href="?page=domains" class="text-primary underline">域名配置</a> 中填写总域名，才能生成正确的访问链接。</p>
                    </div>
                <?php endif; ?>
                
                <?php
                $records = readRecords();
                
                if (empty($records)):
                ?>
                    <div class="bg-blue-50 border-l-4 border-blue-400 text-blue-700 p-4 mb-4">
                        <p class="font-bold">暂无记录</p>
                        <p>您还没有处理任何视频，请先上传或通过URL下载视频。</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">缩略图</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">标题</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">来源</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">状态</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">创建时间</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($records as $record):
                                    $videoUrl = buildVideoAssetUrl($record['directory'], 'index.m3u8');
                                    $imageUrl = $record['screenshot'] ? buildImageAssetUrl($record['directory']) : '';
                                ?>
                                    <tr class="hover:bg-gray-50 transition-custom hover-scale">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($record['screenshot'] && $imageUrl): ?>
                                                <img src="<?php echo htmlspecialchars($imageUrl); ?>" 
                                                     alt="<?php echo htmlspecialchars($record['title']); ?> 截图" 
                                                     class="h-16 w-24 object-cover rounded-md shadow-sm">
                                            <?php else: ?>
                                                <div class="h-16 w-24 bg-gray-100 rounded-md flex items-center justify-center">
                                                    <i class="fa fa-image text-gray-400"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($record['title']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-500">
                                                <?php if ($record['source'] === 'url'): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                        <i class="fa fa-link mr-1"></i>URL下载
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                        <i class="fa fa-upload mr-1"></i>文件上传
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($record['disguised']): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                        <i class="fa fa-mask mr-1"></i>已伪装
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-500"><?php echo $record['created_at']; ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <?php if ($videoUrl): ?>
                                                <button class="copy-btn bg-blue-100 text-blue-800 px-3 py-1 rounded-md hover:bg-blue-200 transition-custom" 
                                                        data-url="<?php echo htmlspecialchars($videoUrl); ?>" 
                                                        data-type="m3u8">
                                                    <i class="fa fa-link mr-1"></i> M3U8链接
                                                </button>
                                                <?php else: ?>
                                                <button class="bg-gray-100 text-gray-500 px-3 py-1 rounded-md cursor-not-allowed" disabled title="请先配置总域名">
                                                    <i class="fa fa-link mr-1"></i> M3U8链接
                                                </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($record['screenshot'] && $imageUrl): ?>
                                                    <button class="copy-btn bg-green-100 text-green-800 px-3 py-1 rounded-md hover:bg-green-200 transition-custom" 
                                                            data-url="<?php echo htmlspecialchars($imageUrl); ?>" 
                                                            data-type="screenshot">
                                                        <i class="fa fa-picture-o mr-1"></i> 图片链接
                                                    </button>
                                                <?php else: ?>
                                                    <button class="bg-gray-100 text-gray-500 px-3 py-1 rounded-md cursor-not-allowed" disabled>
                                                        <i class="fa fa-picture-o mr-1"></i> 无图片
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($videoUrl): ?>
                                                <button class="play-btn bg-orange-100 text-orange-800 px-3 py-1 rounded-md hover:bg-orange-200 transition-custom" 
                                                        data-url="<?php echo htmlspecialchars($videoUrl); ?>" 
                                                        data-title="<?php echo htmlspecialchars($record['title']); ?>">
                                                    <i class="fa fa-play mr-1"></i> 播放
                                                </button>
                                                <?php else: ?>
                                                <button class="bg-gray-100 text-gray-500 px-3 py-1 rounded-md cursor-not-allowed" disabled title="请先配置总域名">
                                                    <i class="fa fa-play mr-1"></i> 播放
                                                </button>
                                                <?php endif; ?>
                                                
                                                <a href="?page=records&action=delete&id=<?php echo $record['id']; ?>" 
                                                   class="bg-red-100 text-red-800 px-3 py-1 rounded-md hover:bg-red-200 transition-custom"
                                                   onclick="return confirm('确定要删除此记录吗？此操作不可撤销。')">
                                                    <i class="fa fa-trash mr-1"></i> 删除
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    
    <!-- 视频播放模态框 -->
    <div id="videoModal" class="fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center hidden">
        <div class="w-full max-w-4xl p-4">
            <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
                <div class="flex justify-between items-center p-4 bg-dark text-white">
                    <h3 class="text-lg font-medium">播放视频</h3>
                    <button id="closeModal" class="text-white hover:text-gray-300 transition-custom">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-4">
                    <!-- 使用视频容器实现响应式 -->
                    <div class="video-container">
                        <video id="videoPlayer" class="video-js vjs-default-skin" controls preload="auto"></video>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 引入Video.js脚本 -->
    <script src="./play/cnf.js"></script>
    <!-- 引入HLS插件 -->
    <script src="./play/cnf-hls.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 切换上传/URL下载标签
            const uploadTab = document.getElementById('upload-tab');
            const urlTab = document.getElementById('url-tab');
            const uploadSection = document.getElementById('upload-section');
            const urlSection = document.getElementById('url-section');
            
            if (uploadTab && urlTab && uploadSection && urlSection) {
                uploadTab.addEventListener('click', function(e) {
                    e.preventDefault();
                    uploadTab.classList.add('border-primary', 'text-primary');
                    uploadTab.classList.remove('border-transparent', 'text-gray-500');
                    urlTab.classList.add('border-transparent', 'text-gray-500');
                    urlTab.classList.remove('border-primary', 'text-primary');
                    uploadSection.classList.remove('hidden');
                    urlSection.classList.add('hidden');
                });
                
                urlTab.addEventListener('click', function(e) {
                    e.preventDefault();
                    urlTab.classList.add('border-primary', 'text-primary');
                    urlTab.classList.remove('border-transparent', 'text-gray-500');
                    uploadTab.classList.add('border-transparent', 'text-gray-500');
                    uploadTab.classList.remove('border-primary', 'text-primary');
                    urlSection.classList.remove('hidden');
                    uploadSection.classList.add('hidden');
                });
            }
            
            // URL测试功能
            const testUrlBtn = document.getElementById('test-url');
            const videoUrlInput = document.getElementById('video_url');
            const urlTestResult = document.getElementById('url-test-result');
            
            if (testUrlBtn && videoUrlInput && urlTestResult) {
                testUrlBtn.addEventListener('click', function() {
                    const url = videoUrlInput.value.trim();
                    
                    if (!url) {
                        showUrlTestResult('请输入视频URL', 'error');
                        return;
                    }
                    
                    if (!isValidUrl(url)) {
                        showUrlTestResult('请输入有效的URL', 'error');
                        return;
                    }
                    
                    showUrlTestResult('正在测试URL...', 'info');
                    
                    // 简单的URL格式验证
                    const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'webm'];
                    const urlParts = url.split('.');
                    const extension = urlParts[urlParts.length - 1].toLowerCase();
                    
                    if (videoExtensions.includes(extension)) {
                        showUrlTestResult('URL看起来有效，包含视频文件扩展名', 'success');
                    } else {
                        showUrlTestResult('URL不包含常见的视频文件扩展名，可能无法下载', 'warning');
                    }
                });
            }
            
            // 显示URL测试结果
            function showUrlTestResult(message, type) {
                urlTestResult.classList.remove('hidden', 'bg-green-50', 'bg-red-50', 'bg-yellow-50', 
                    'border-green-400', 'border-red-400', 'border-yellow-400', 
                    'text-green-700', 'text-red-700', 'text-yellow-700');
                
                let bgClass, borderClass, textClass, icon;
                
                switch(type) {
                    case 'success':
                        bgClass = 'bg-green-50';
                        borderClass = 'border-green-400';
                        textClass = 'text-green-700';
                        icon = 'fa-check-circle';
                        break;
                    case 'error':
                        bgClass = 'bg-red-50';
                        borderClass = 'border-red-400';
                        textClass = 'text-red-700';
                        icon = 'fa-exclamation-circle';
                        break;
                    case 'warning':
                        bgClass = 'bg-yellow-50';
                        borderClass = 'border-yellow-400';
                        textClass = 'text-yellow-700';
                        icon = 'fa-exclamation-triangle';
                        break;
                    default:
                        bgClass = 'bg-blue-50';
                        borderClass = 'border-blue-400';
                        textClass = 'text-blue-700';
                        icon = 'fa-info-circle';
                }
                
                urlTestResult.classList.add(bgClass, borderClass, textClass);
                urlTestResult.innerHTML = `<i class="fa ${icon} mr-2"></i>${message}`;
            }
            
            // URL验证函数
            function isValidUrl(url) {
                try {
                    new URL(url);
                    return true;
                } catch (e) {
                    return false;
                }
            }
            
            // 复制链接功能
            document.querySelectorAll('.copy-btn').forEach(btn => {
                if (btn.disabled) return;
                
                btn.addEventListener('click', function() {
                    const url = this.getAttribute('data-url');
                    const type = this.getAttribute('data-type');
                    
                    navigator.clipboard.writeText(url)
                        .then(() => {
                            // 保存原始文本
                            const originalText = this.innerHTML;
                            
                            // 显示成功消息
                            this.innerHTML = `<i class="fa fa-check mr-1"></i> 已复制`;
                            this.classList.remove('bg-blue-100', 'bg-green-100');
                            this.classList.add('bg-green-100');
                            
                            // 2秒后恢复原状
                            setTimeout(() => {
                                this.innerHTML = originalText;
                                this.classList.remove('bg-green-100');
                                this.classList.add(type === 'm3u8' ? 'bg-blue-100' : 'bg-green-100');
                            }, 2000);
                        })
                        .catch(err => {
                            console.error('复制失败:', err);
                            alert('复制失败，请手动复制');
                        });
                });
            });
            
            // 文件选择显示功能
            const fileInput = document.getElementById('video');
            const uploadArea = document.getElementById('upload-area');
            const selectedFile = document.getElementById('selected-file');
            const fileName = document.getElementById('file-name');
            const fileSize = document.getElementById('file-size');
            const removeFile = document.getElementById('remove-file');
            
            if (fileInput && uploadArea && selectedFile && fileName && fileSize && removeFile) {
                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        fileName.textContent = file.name;
                        fileSize.textContent = formatFileSize(file.size);
                        
                        // 显示已选择文件区域，隐藏上传区域
                        uploadArea.classList.add('hidden');
                        selectedFile.classList.remove('hidden');
                    }
                });
                
                // 移除文件功能
                removeFile.addEventListener('click', function() {
                    fileInput.value = '';
                    uploadArea.classList.remove('hidden');
                    selectedFile.classList.add('hidden');
                });
                
                // 格式化文件大小的函数（客户端）
                function formatFileSize(bytes) {
                    if (bytes === 0) return '0 Bytes';
                    
                    const units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(1024));
                    
                    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
                }
            }
            
            // 仅拦截视频上传表单，避免影响域名/API 等配置表单
            const form = document.getElementById('video-upload-form');
            const progressContainer = document.getElementById('progress-container');
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            
            if (form && progressContainer && progressBar && progressText) {
                form.addEventListener('submit', function(event) {
                    // 验证输入
                    const urlValue = document.getElementById('video_url')?.value?.trim() || '';
                    const fileValue = document.getElementById('video')?.files?.length > 0;
                    
                    // 如果是URL模式但URL为空
                    if (urlSection && !urlSection.classList.contains('hidden') && !urlValue) {
                        alert('请输入视频URL地址');
                        event.preventDefault();
                        return;
                    }
                    
                    // 如果是文件模式但未选择文件
                    if (uploadSection && !uploadSection.classList.contains('hidden') && !fileValue) {
                        alert('请选择视频文件');
                        event.preventDefault();
                        return;
                    }
                    
                    event.preventDefault();
                    
                    progressContainer.classList.remove('hidden');
                    progressBar.style.width = '0%';
                    
                    // 根据类型显示不同的初始消息
                    if (urlValue) {
                        progressText.textContent = '正在准备下载视频...';
                    } else {
                        progressText.textContent = '准备上传...';
                    }
                    
                    const xhr = new XMLHttpRequest();
                    const formData = new FormData(form);
                    
                    const uploadAction = form.getAttribute('data-upload-action') || form.getAttribute('action') || 'index.php';
                    xhr.open('POST', uploadAction, true);
                    
                    // 监听上传进度
                    xhr.upload.addEventListener('progress', function(event) {
                        if (event.lengthComputable) {
                            const percentComplete = Math.round((event.loaded / event.total) * 100);
                            progressBar.style.width = percentComplete + '%';
                            
                            if (urlValue) {
                                progressText.textContent = `正在下载视频: ${percentComplete}%`;
                            } else {
                                progressText.textContent = `正在上传: ${percentComplete}%`;
                            }
                        }
                    });
                    
                    // 上传完成
                    xhr.addEventListener('load', function() {
                        if (xhr.status === 200) {
                            progressText.textContent = '处理完成！页面即将刷新...';
                            progressBar.style.width = '100%';
                            setTimeout(function() {
                                window.location.reload();
                            }, 2000);
                        } else {
                            progressText.textContent = '处理失败，请重试';
                        }
                    });
                    
                    // 错误处理
                    xhr.addEventListener('error', function() {
                        progressText.textContent = '网络错误，请检查连接';
                    });
                    
                    xhr.addEventListener('abort', function() {
                        progressText.textContent = '操作已取消';
                    });
                    
                    // 发送请求
                    xhr.send(formData);
                });
            }
            
            // 视频播放功能
            const videoModal = document.getElementById('videoModal');
            const videoPlayer = videojs('videoPlayer');
            const closeModal = document.getElementById('closeModal');
            
            // 确保视频播放器正确初始化
            if (videoPlayer) {
                videoPlayer.ready(function() {
                    // 播放器配置
                    this.controlled(true);
                    this.autoplay(false);
                    this.preload('auto');
                    
                    // 添加响应式支持
                    this.addClass('vjs-fluid');
                });
            }
            
            // 播放按钮点击事件
            document.querySelectorAll('.play-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const videoUrl = this.getAttribute('data-url');
                    const videoTitle = this.getAttribute('data-title');
                    
                    // 设置视频源
                    if (videoPlayer) {
                        videoPlayer.src({
                            type: 'application/x-mpegURL',
                            src: videoUrl
                        });
                        
                        // 更新模态框标题
                        document.querySelector('#videoModal h3').textContent = `播放: ${videoTitle}`;
                        
                        // 显示模态框
                        videoModal.classList.remove('hidden');
                        
                        // 加载并播放视频
                        videoPlayer.load();
                        videoPlayer.play();
                    }
                });
            });
            
            // 关闭模态框
            closeModal.addEventListener('click', function() {
                videoModal.classList.add('hidden');
                if (videoPlayer) {
                    videoPlayer.pause();
                }
            });
            
            // 点击模态框外部关闭
            videoModal.addEventListener('click', function(event) {
                if (event.target === videoModal && videoPlayer) {
                    videoModal.classList.add('hidden');
                    videoPlayer.pause();
                }
            });
            
            // ESC键关闭模态框
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && videoPlayer) {
                    videoModal.classList.add('hidden');
                    videoPlayer.pause();
                }
            });
        });
    </script>
</body>
</html>