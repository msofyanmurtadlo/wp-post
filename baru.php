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

    $output = '';
    
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

        if (empty($categories)) {
            $output .= '<span class="error">Error: Kategori tidak boleh kosong.</span>' . "\n";
        } elseif (empty($tags)) {
            $output .= '<span class="error">Error: Tag tidak boleh kosong.</span>' . "\n";
        } else {
            $chunkSize = 10;
            $domainChunks = array_chunk($domains, $chunkSize);

            foreach ($domainChunks as $chunk) {
                $domainResults = createPostsForDomains($chunk, $postTitle, $postContent, $postexcerpt, $categories, $tags,$keywords);
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

function createPostsForDomains($domains, $postTitle, $postContent, $postexcerpt, $categories, $tags, $keywords) {
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

       if(!empty($keywords)) { 
            $randomKeyword = $keywords[array_rand($keywords)];
            $title = str_replace('@Keyword', $randomKeyword, $postTitle);
        } else {
            $title = $postTitle;
        }

      
        $content = str_replace(['@Domain', '@Judul'], [$domain, $title], $postContent);
        $excerpt = str_replace(['@Domain', '@Judul'], [$domain, $title], $postexcerpt);

        $postData = [
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'status' => 'draft',
            'categories' => $catIds,
            'tags' => $tagIds
        ];

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

function getCategoryIds($domain, $username, $password, $categories) {
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

function getTagIds($domain, $username, $password, $tags) {
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

function createCategory($domain, $username, $password, $name) {
    $url = "https://$domain/wp-json/wp/v2/categories";
    $response = wpPost($url, $username, $password, ['name' => $name]);
    return $response['id'] ?? null;
}

function createTag($domain, $username, $password, $name) {
    $url = "https://$domain/wp-json/wp/v2/tags";
    $response = wpPost($url, $username, $password, ['name' => $name]);
    return $response['id'] ?? null;
}

function wpPost($url, $username, $password, $data) {
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background-color: #f4f4f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-card { border-radius: 15px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1); background: #ffffff; }
        .card-header-custom {
            background: linear-gradient(135deg, #4e73df, #6f42c1);
            color: white;
            padding: 1.5rem;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .form-label { font-weight: 600; color: #333; }
        textarea, input { border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-primary { background-color: #4e73df; border: none; transition: all 0.3s ease; }
        .btn-primary:hover { background-color: #375ab6; }
        .card-footer { background-color: #f8f9fc; border-bottom-left-radius: 15px; border-bottom-right-radius: 15px; padding: 1rem; text-align: center; }
        #logOutput { background-color: #f1f1f1; color: #000; font-family: monospace; white-space: pre-wrap; word-wrap: break-word; }
        .error { color: red; }
        .success { color: green; }
        .info { color: blue; }
    </style>
</head>
<body>
<div class="container my-5">
    <div class="main-card">
        <div class="card-header-custom">
            <h3>🚀 Auto Post to WordPress</h3>
            <p class="mb-0 text-light small">Posting cepat ke banyak domain WordPress hanya dengan sekali klik.</p>
        </div>
        <div class="p-4">
            <form id="postForm">
                <div class="row">
                    <div class="col-md-4 ">
                        <div class="mb-3">
                            <label class="form-label">Daftar Website</label>
                            <textarea name="domain" class="form-control" rows="8" placeholder="domain.com:username:password"><?= htmlspecialchars($_POST['domain'] ?? '') ?></textarea>
                            <small class="text-muted">Pisahkan per baris: <code>domain.com:username:password</code></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Daftar Keyword</label>
                            <textarea name="keywords" class="form-control" rows="8" placeholder="Masukkan keywords (1 per baris)"><?= htmlspecialchars($_POST['keywords'] ?? '') ?></textarea>
                            <small class="text-muted">Pisahkan setiap <code>keyword</code> dengan enter.</small>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Judul Post</label>
                                <input name="postTitle" class="form-control mb-3" placeholder="Judul" value="<?= htmlspecialchars($_POST['postTitle'] ?? '') ?>">
                                <small class="text-muted">Gunakan <code>@Keyword</code> untuk replace otomatis.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Deskripsi</label>
                                <input name="excerpt" class="form-control mb-3" placeholder="Deskripsi" value="<?= htmlspecialchars($_POST['excerpt'] ?? '') ?>">
                                <small class="text-muted">Gunakan <code>@Domain</code> dan <code>@Judul</code> untuk replace otomatis.</small>
                            </div>
                        </div>
                        <label class="form-label">Konten Post</label>
                        <textarea name="postContent" class="form-control mb-2" rows="5" placeholder="Konten..."><?= htmlspecialchars($_POST['postContent'] ?? '') ?></textarea>
                        <small class="text-muted">Gunakan <code>@Domain</code> dan <code>@Judul</code> untuk replace otomatis.</small>
                        <div class="row mt-3 mb-2">
                            <div class="col">
                                <label class="form-label">Kategori</label>
                                <textarea name="categories" class="form-control" rows="3" placeholder="Misal: News, Tutorial"><?= htmlspecialchars($_POST['categories'] ?? '') ?></textarea>
                                <small class="text-muted">Pisahkan setiap kategori dengan koma.</small>
                            </div>
                            <div class="col">
                                <label class="form-label">Tags</label>
                                <textarea name="tags" class="form-control" rows="3" placeholder="Misal: WordPress, Otomatis"><?= htmlspecialchars($_POST['tags'] ?? '') ?></textarea>
                                <small class="text-muted">Pisahkan setiap Tag dengan koma.</small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary mt-2 px-4 py-2" id="submitBtn">Jalankan Post</button>
                        <div id="logOutput" class="form-control mt-3" rows="10" readonly>Status postingan!!!</div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('#postForm').on('submit', function(event) {
            event.preventDefault();
            var formData = $(this).serialize();
            $('#logOutput').text('Sedang Memproses...');
            $('#submitBtn').text('Memproses...').prop('disabled', true);
            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                success: function(response) {
                    $('#logOutput').html(response);
                    $('#submitBtn').text('Jalankan Post').prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    $('#logOutput').html('<span class="error">Terjadi kesalahan: ' + error + '</span>');
                    $('#submitBtn').text('Jalankan Post').prop('disabled', false);
                }
            });
        });
    });
</script>
</body>
</html>
