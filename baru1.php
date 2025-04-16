<?php
set_time_limit(0);
ini_set('max_execution_time', 0);
ob_implicit_flush(true);
ob_end_flush();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postTitle = $_POST['postTitle'] ?? '';
    $postContent = $_POST['postContent'] ?? '';
    $postexcerpt = $_POST['excerpt'] ?? '';
    $domainInput = $_POST['domain'] ?? '';
    $categories = $_POST['categories'] ?? '';
    $tags = $_POST['tags'] ?? '';
    $keywordsInput = $_POST['keywords'] ?? '';
    $featuredImage = $_FILES['featured_image'] ?? null;
    $status = isset($_POST['postStatus']) && $_POST['postStatus'] == 'on' ? 'publish' : 'draft';

    $output = '';

    $uploadError = false;

    if (empty($domainInput)) {
        $output .= '<span class="error">Error: Harap masukkan domain.</span>' . "\n";
    } elseif (empty($postTitle)) {
        $output .= '<span class="error">Error: Judul harus diisi.</span>' . "\n";
    } elseif (empty($postexcerpt)) {
        $output .= '<span class="error">Error: Deskripsi harus diisi.</span>' . "\n";
    } elseif (empty($postContent)) {
        $output .= '<span class="error">Error: Konten harus diisi.</span>' . "\n";
    } else {
        $domains = array_filter(array_map('trim', explode("\n", $domainInput)));
        $categories = array_filter(array_map('trim', explode(',', $categories)));
        $tags = array_filter(array_map('trim', explode(',', $tags)));
        $keywords = array_filter(array_map('trim', explode("\n", $keywordsInput)));
        $keywords = array_values($keywords);

        $imageIds = [];
        if ($featuredImage && $featuredImage['error'] == UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($featuredImage['type'], $allowedTypes)) {
                $output .= '<span class="error">Error: File harus berupa gambar JPEG, PNG, atau GIF.</span>' . "\n";
                $uploadError = true;
            }
            if ($featuredImage['size'] > 5 * 1024 * 1024) {
                $output .= '<span class="error">Error: Ukuran file terlalu besar. Maksimal 5MB.</span>' . "\n";
                $uploadError = true;
            }

            if (!$uploadError) {
                foreach ($domains as $domainData) {
                    $domainParts = explode(':', trim($domainData));
                    if (count($domainParts) === 3) {
                        list($domain, $username, $password) = $domainParts;
                        $imageInfo = uploadFeaturedImage($featuredImage, $domain, $username, $password, $postTitle, $keywords);
                        if ($imageInfo === null) {
                            $output .= '<span class="error">Error: Gagal mengupload featured image ke domain ' . $domain . '.</span>' . "\n";
                            $uploadError = true;
                        } else {
                            $imageIds[$domain] = $imageInfo;
                        }
                    }
                }
            }
        } elseif ($featuredImage && $featuredImage['error'] != UPLOAD_ERR_NO_FILE) {
            $output .= '<span class="error">Error: Gagal mengupload file. Kode error: ' . $featuredImage['error'] . '</span>' . "\n";
            $uploadError = true;
        }
        if (empty($categories)) {
            $output .= '<span class="error">Error: Kategori tidak boleh kosong.</span>' . "\n";
        } elseif (empty($tags)) {
            $output .= '<span class="error">Error: Tag tidak boleh kosong.</span>' . "\n";
        } elseif (!$uploadError) {
            $chunkSize = 10;
            $domainChunks = array_chunk($domains, $chunkSize);

            foreach ($domainChunks as $chunk) {
                $domainResults = createPostsForDomains($chunk, $postTitle, $postContent, $postexcerpt, $categories, $tags, $keywords, $imageIds, $status);
                foreach ($domainResults as $domain => $result) {
                    if ($result === true) {
                        echo '<span class="success">' . $domain . ' berhasil</span>' . "\n";
                        flush();
                    } else {
                        echo '<span class="error">' . $domain . ' gagal (' . $result . ')</span>' . "\n";
                        flush();
                    }
                }
            }
        }
    }
    echo $output;
    flush();
    exit;
}


function uploadFeaturedImage($file, $domain, $username, $password, $postTitle, $keywords)
{
    $url = "https://$domain/wp-json/wp/v2/media";

    $fileName = basename($file['name']);
    $fileType = $file['type'];
    $filePath = $file['tmp_name'];
    if (!empty($keywords)) {
        $randomKeyword = $keywords[array_rand($keywords)];
        $title = str_replace('@Keyword', $randomKeyword, $postTitle);
    } else {
        $title = $postTitle;
    }

    $postFields = [
        'file' => new CURLFile($filePath, $fileType, $fileName),
        'title' => $title,
        'alt_text' => $title,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode("$username:$password"),
        ],
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode == 201) {
        $responseData = json_decode($response, true);
        return [
            'id' => $responseData['id'],
            'url' => $responseData['source_url']
        ];
    } else {
        error_log("Gagal mengupload gambar ke $domain. Kode HTTP: $httpCode, Error: $error, Response: $response");
        return null;
    }
}


function createPostsForDomains($domains, $postTitle, $postContent, $postexcerpt, $categories, $tags, $keywords, $featuredImageIds, $status)
{
    $domainResults = [];
    $mh = curl_multi_init();
    $curlHandles = [];
    $domainNames = [];

    foreach ($domains as $domainData) {
        $parts = explode(':', $domainData);
        if (count($parts) !== 3) {
            $domainResults[$domainData] = "Format tidak valid";
            continue;
        }

        [$domain, $username, $password] = $parts;
        $domainNames[] = $domain;
        $catIds = getCategoryIds($domain, $username, $password, $categories);
        $tagIds = getTagIds($domain, $username, $password, $tags);

        if (!empty($keywords)) {
            $randomKeyword = $keywords[array_rand($keywords)];
            $title = str_replace('@Keyword', $randomKeyword, $postTitle);
        } else {
            $title = $postTitle;
        }

        $content = str_replace(['@Domain', '@Judul'], [$domain, $title], $postContent);
        if (isset($featuredImageIds[$domain]['url'])) {
            $imageTag = '<figure class="wp-block-image aligncenter"><img src="' . htmlspecialchars($featuredImageIds[$domain]['url']) . '" alt="' . htmlspecialchars($title) . '" style="max-width:100%;height:auto;" /></figure>';
            $content = str_replace('@Gambar', $imageTag, $content);
        }

        $excerpt = str_replace(['@Domain', '@Judul'], [$domain, $title], $postexcerpt);

        $postData = [
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'status' =>  $status,
            'categories' => $catIds,
            'tags' => $tagIds,
        ];

        if (isset($featuredImageIds[$domain]['id'])) {
            $postData['featured_media'] = $featuredImageIds[$domain]['id'];
        }

        $ch = curl_init("https://$domain/wp-json/wp/v2/posts");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode("$username:$password"),
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 120,
        ]);

        curl_multi_add_handle($mh, $ch);
        $curlHandles[] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    foreach ($curlHandles as $index => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $domain = $domainNames[$index];
        curl_multi_remove_handle($mh, $ch);

        if ($httpCode == 201) {
            $domainResults[$domain] = true;
        } else {
            $domainResults[$domain] = "HTTP $httpCode: " . ($response ? json_decode($response)->message : 'Tidak ada pesan');
        }
    }

    curl_multi_close($mh);
    return $domainResults;
}

function getCategoryIds($domain, $username, $password, $categories)
{
    $ids = [];
    $mh = curl_multi_init();
    $curlHandles = [];

    foreach ($categories as $name) {
        $url = "https://$domain/wp-json/wp/v2/categories?search=" . urlencode($name);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$username:$password")]
        ]);
        curl_multi_add_handle($mh, $ch);
        $curlHandles[] = [$ch, $name];
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    foreach ($curlHandles as [$ch, $name]) {
        $response = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);

        if ($code == 200 && ($data = json_decode($response, true)) && !empty($data[0]['id'])) {
            $ids[] = $data[0]['id'];
        } else {
            $created = createCategory($domain, $username, $password, $name);
            if ($created) $ids[] = $created;
        }
    }

    curl_multi_close($mh);
    return $ids;
}

function getTagIds($domain, $username, $password, $tags)
{
    $ids = [];
    $mh = curl_multi_init();
    $curlHandles = [];

    foreach ($tags as $name) {
        $url = "https://$domain/wp-json/wp/v2/tags?search=" . urlencode($name);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$username:$password")]
        ]);
        curl_multi_add_handle($mh, $ch);
        $curlHandles[] = [$ch, $name];
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    foreach ($curlHandles as [$ch, $name]) {
        $response = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);

        if ($code == 200 && ($data = json_decode($response, true)) && !empty($data[0]['id'])) {
            $ids[] = $data[0]['id'];
        } else {
            $created = createTag($domain, $username, $password, $name);
            if ($created) $ids[] = $created;
        }
    }

    curl_multi_close($mh);
    return $ids;
}

function createCategory($domain, $username, $password, $name)
{
    $url = "https://$domain/wp-json/wp/v2/categories";
    $response = wpPost($url, $username, $password, ['name' => $name]);
    return $response['id'] ?? null;
}

function createTag($domain, $username, $password, $name)
{
    $url = "https://$domain/wp-json/wp/v2/tags";
    $response = wpPost($url, $username, $password, ['name' => $name]);
    return $response['id'] ?? null;
}

function wpPost($url, $username, $password, $data)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode("$username:$password"),
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
?>


<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>WP POSTER</title>
    <link rel="icon" href="https://s.w.org/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4895ef;
            --dark-color: #1a1a2e;
            --light-color: #f8f9fa;
            --success-color: #4cc9f0;
            --warning-color: #f72585;
            --border-radius: 8px;
            --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        body {
            background-color: #f5f7ff;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: var(--dark-color);
            line-height: 1.5;
        }

        .main-card {
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            background: #ffffff;
            border: none;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .log-container {
            background-color: #fff;
            border-radius: var(--border-radius);
            border: 1px solid #e0e0e0;
            padding: 0;
            margin-top: 20px;
        }

        .log-header {
            padding: 0.75rem 1rem;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            color: #555;
            display: flex;
            align-items: center;
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
        }

        .log-header i {
            margin-right: 8px;
            color: #666;
        }

        .card-header-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
        }

        .card-header-custom h3 {
            font-weight: 700;
            margin-bottom: 0.25rem;
            font-size: 1.5rem;
        }

        .card-header-custom p {
            opacity: 0.9;
            font-weight: 400;
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        textarea,
        input,
        select {
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            transition: var(--transition);
            padding: 0.65rem 0.9rem;
            font-size: 0.9rem;
        }

        textarea:focus,
        input:focus,
        select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            outline: none;
        }

        .form-control {
            background-color: #fcfcfc;
        }

        .form-control::placeholder {
            color: #aaa;
            font-weight: 400;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border: none;
            transition: var(--transition);
            padding: 0.65rem 1.75rem;
            font-weight: 600;
            font-size: 0.9rem;
            border-radius: 6px;
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(67, 97, 238, 0.15);
        }

        #logOutput {
            background-color: #fcfcfc;
            color: var(--dark-color);
            font-family: 'Fira Code', monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            padding: 1rem;
            min-height: 120px;
            max-height: 250px;
            overflow-y: auto;
            font-size: 0.8rem;
            line-height: 1.6;
            border: none;
            margin: 0;
        }

        .error {
            color: var(--warning-color);
        }

        .success {
            color: #2ecc71;
        }

        .info {
            color: var(--accent-color);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 22px;
            margin-left: 8px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.slider {
            background-color: var(--primary-color);
        }

        input:checked+.slider:before {
            transform: translateX(20px);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--dark-color);
        }

        .status-indicator .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }

        .status-draft .status-dot {
            background-color: #95a5a6;
        }

        .status-published .status-dot {
            background-color: #2ecc71;
        }

        .section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 8px;
            color: var(--primary-color);
            font-size: 0.9rem;
        }

        .form-section {
            background-color: #f8f9ff;
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            border: 1px solid #e0e0e0;
        }

        .badge-info {
            background-color: var(--accent-color);
            font-weight: 500;
            font-size: 0.7rem;
            padding: 0.3rem 0.5rem;
        }

        .tab-content {
            padding: 1rem 0;
        }

        .nav-tabs .nav-link {
            border: none;
            color: #666;
            font-weight: 500;
            font-size: 0.85rem;
            padding: 0.65rem 1.25rem;
            border-radius: 6px 6px 0 0;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background-color: transparent;
            border-bottom: 2px solid var(--primary-color);
        }

        .nav-tabs {
            border-bottom: 1px solid #e0e0e0;
        }

        .progress {
            height: 4px;
            border-radius: 0;
            margin: 0;
        }

        .progress-bar {
            background-color: var(--accent-color);
            transition: width 0.6s ease;
        }

        .form-hint {
            font-size: 0.75rem;
            color: #666;
            margin-top: 0.25rem;
        }

        .form-hint code {
            font-size: 0.7rem;
            background-color: rgba(67, 97, 238, 0.1);
            padding: 0.15rem 0.3rem;
            border-radius: 3px;
            color: var(--primary-color);
        }

        @media (max-width: 768px) {
            .card-header-custom {
                padding: 1.2rem;
            }

            .form-section {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="container py-3">
        <div class="main-card">
            <div class="card-header-custom">
                <h3><i class="fas fa-rocket me-2"></i>Auto Post to WordPress</h3>
                <p class="mb-0">Posting otomatis ke banyak WordPress sekaligus</p>
            </div>
            <div class="p-3">
                <form id="postForm" method="POST" enctype="multipart/form-data">
                    <ul class="nav nav-tabs" id="myTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="websites-tab" data-bs-toggle="tab" data-bs-target="#websites" type="button" role="tab">Websites</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="content-tab" data-bs-toggle="tab" data-bs-target="#content" type="button" role="tab">Konten</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab">Pengaturan</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="myTabContent">
                        <div class="tab-pane fade show active" id="websites" role="tabpanel">
                            <div class="form-section">
                                <div class="section-title">
                                    <i class="fas fa-globe"></i>
                                    <span>Daftar Website</span>
                                    <span class="badge badge-info ms-2">Wajib</span>
                                </div>
                                <textarea name="domain" class="form-control" rows="6" placeholder="domain.com:username:password"><?= htmlspecialchars($_POST['domain'] ?? '') ?></textarea>
                                <div class="form-hint">Format: <code>domain.com:username:password</code> (satu website per baris)</div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="content" role="tabpanel">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-section">
                                        <div class="section-title">
                                            <i class="fas fa-align-left"></i>
                                            <span>Konten Post</span>
                                        </div>
                                        <textarea name="postContent" class="form-control" rows="6" placeholder="Isi konten postingan..."><?= htmlspecialchars($_POST['postContent'] ?? '') ?></textarea>
                                        <div class="form-hint">Gunakan <code>@Domain</code>, <code>@Judul</code>, dan <code>@Gambar</code> untuk variabel dinamis</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-section">
                                        <div class="section-title">
                                            <i class="fas fa-paragraph"></i>
                                            <span>Deskripsi Singkat</span>
                                        </div>
                                        <textarea name="excerpt" class="form-control" rows="6" placeholder="Deskripsi singkat..."><?= htmlspecialchars($_POST['excerpt'] ?? '') ?></textarea>
                                        <div class="form-hint">Gunakan <code>@Domain</code> dan <code>@Judul</code> untuk variabel dinamis</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <div class="section-title">
                                            <i class="fas fa-tags"></i>
                                            <span>Kategori</span>
                                        </div>
                                        <textarea name="categories" class="form-control" rows="2" placeholder="News, Tutorial"><?= htmlspecialchars($_POST['categories'] ?? '') ?></textarea>
                                        <div class="form-hint">Pisahkan dengan koma</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <div class="section-title">
                                            <i class="fas fa-tags"></i>
                                            <span>Tags</span>
                                        </div>
                                        <textarea name="tags" class="form-control" rows="2" placeholder="WordPress, Otomatis"><?= htmlspecialchars($_POST['tags'] ?? '') ?></textarea>
                                        <div class="form-hint">Pisahkan dengan koma</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section mt-3">
                                <div class="section-title">
                                    <i class="fas fa-toggle-on"></i>
                                    <span>Status Post</span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <label class="form-label mb-0">Status:</label>
                                    <label class="switch ms-2">
                                        <input type="checkbox" name="postStatus" id="postStatus">
                                        <span class="slider"></span>
                                    </label>
                                    <span id="statusLabel" class="status-indicator status-draft ms-2">
                                        <span class="status-dot"></span>
                                        <span>Draft</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="settings" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <div class="section-title">
                                            <i class="fas fa-key"></i>
                                            <span>Keywords</span>
                                        </div>
                                        <textarea name="keywords" class="form-control" rows="6" placeholder="Masukkan keywords (1 per baris)"><?= htmlspecialchars($_POST['keywords'] ?? '') ?></textarea>
                                        <div class="form-hint">Satu keyword per baris</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-section">
                                        <div class="section-title">
                                            <i class="fas fa-heading"></i>
                                            <span>Judul Post</span>
                                        </div>
                                        <input name="postTitle" class="form-control mb-3" placeholder="Judul postingan" value="<?= htmlspecialchars($_POST['postTitle'] ?? '') ?>">
                                        <div class="form-hint">Gunakan <code>@Keyword</code> untuk variabel dinamis</div>

                                        <div class="section-title mt-3">
                                            <i class="fas fa-image"></i>
                                            <span>Featured Image</span>
                                        </div>
                                        <input type="file" name="featured_image" class="form-control">
                                        <div class="form-hint">Upload gambar utama postingan</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>



                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="text-muted small">
                            <i class="fas fa-info-circle me-1"></i> WP POSTER
                        </div>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane me-2"></i>Jalankan Posting
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="log-container">
            <div class="log-header">
                <i class="fas fa-terminal"></i>
                <span>Log Proses</span>
            </div>
            <div id="logOutput">Sistem siap menjalankan posting...</div>
            <div class="progress d-none" id="progressBar">
                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#postStatus').on('change', function() {
                if (this.checked) {
                    $('#statusLabel').removeClass('status-draft').addClass('status-published').find('span:last').text('Published');
                } else {
                    $('#statusLabel').removeClass('status-published').addClass('status-draft').find('span:last').text('Draft');
                }
            });

            $('#postForm').on('submit', function(event) {
                event.preventDefault();
                var formData = new FormData(this);
                $('#logOutput').html('<span class="info"><i class="fas fa-spinner fa-spin me-2"></i>Memproses postingan...</span>');
                $('#submitBtn').html('<i class="fas fa-spinner fa-spin me-2"></i>Memproses...').prop('disabled', true);
                $('#progressBar').removeClass('d-none').find('.progress-bar').css('width', '0%');

                var progress = 0;
                var progressInterval = setInterval(function() {
                    progress += 5;
                    if (progress <= 90) {
                        $('#progressBar .progress-bar').css('width', progress + '%');
                    }
                }, 300);

                $.ajax({
                    url: '',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        clearInterval(progressInterval);
                        $('#progressBar .progress-bar').css('width', '100%');
                        $('#logOutput').html(response);
                        $('#submitBtn').html('<i class="fas fa-paper-plane me-2"></i>Jalankan Posting').prop('disabled', false);
                        setTimeout(function() {
                            $('#progressBar').addClass('d-none');
                        }, 1000);
                    },
                    error: function(xhr, status, error) {
                        clearInterval(progressInterval);
                        $('#progressBar .progress-bar').css('width', '100%');
                        $('#logOutput').html('<span class="error"><i class="fas fa-exclamation-circle me-2"></i>Error: ' + error + '</span>');
                        $('#submitBtn').html('<i class="fas fa-paper-plane me-2"></i>Jalankan Posting').prop('disabled', false);
                        setTimeout(function() {
                            $('#progressBar').addClass('d-none');
                        }, 1000);
                    }
                });
            });

            var tabElms = document.querySelectorAll('button[data-bs-toggle="tab"]');
            tabElms.forEach(function(tabEl) {
                tabEl.addEventListener('shown.bs.tab', function(event) {
                    event.target.classList.add('active');
                    event.relatedTarget.classList.remove('active');
                });
            });
        });
    </script>
</body>

</html>