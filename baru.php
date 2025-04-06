<?php
set_time_limit(0);
ini_set('max_execution_time', 0);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postTitle = $_POST['postTitle'] ?? '';
    $postContent = $_POST['postContent'] ?? '';
    $domainInput = $_POST['domain'] ?? '';
    $categories = $_POST['categories'] ?? '';
    $tags = $_POST['tags'] ?? '';

    $responseMessage = '';
    $responseType = '';

    if (empty($postTitle) || empty($postContent)) {
        $responseMessage = 'Judul dan konten harus diisi.';
        $responseType = 'danger';
    } elseif (empty($domainInput)) {
        $responseMessage = 'Harap masukkan domain.';
        $responseType = 'danger';
    } else {
        $domains = array_filter(array_map('trim', explode("\n", $domainInput)));
        $categories = array_filter(array_map('trim', explode(',', $categories)));
        $tags = array_filter(array_map('trim', explode(',', $tags)));

        if (empty($categories)) {
            $responseMessage = 'Kategori tidak boleh kosong.';
            $responseType = 'danger';
        } elseif (empty($tags)) {
            $responseMessage = 'Tag tidak boleh kosong.';
            $responseType = 'danger';
        } else {
            $domainResults = createPostsForDomains($domains, $postTitle, $postContent, $categories, $tags);

            $successDomains = [];
            $failedDomains = [];
            foreach ($domainResults as $domain => $result) {
                if ($result === true) {
                    $successDomains[] = $domain;
                } else {
                    $failedDomains[] = "$domain ($result)";
                }
            }

            $responseMessage = '';
            if ($successDomains) {
                $responseMessage .= implode("\n", array_map(fn($domain) => "$domain berhasil", $successDomains)) . "\n";
            }
            if ($failedDomains) {
                $responseMessage .= implode("\n", array_map(fn($domain) => "$domain gagal", $failedDomains)) . "\n";
            }
        }
    }

    echo $responseMessage;
    exit;
}

function createPostsForDomains($domains, $postTitle, $postContent, $categories, $tags) {
    $domainResults = [];

    foreach ($domains as $domainData) {
        $parts = explode(':', $domainData);
        if (count($parts) !== 3) {
            $domainResults[$domainData] = "Format tidak valid";
            continue;
        }

        [$domain, $username, $password] = $parts;

        $catIds = getCategoryIds($domain, $username, $password, $categories);
        $tagIds = getTagIds($domain, $username, $password, $tags);

        $postData = [
            'title' => $postTitle,
            'content' => str_replace(['@Domain', '@Judul'], [$domain, $postTitle], $postContent),
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
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $domainResults[$domain] = ($httpCode == 201) ? true : "HTTP $httpCode";
    }

    return $domainResults;
}

function getCategoryIds($domain, $username, $password, $categories) {
    $ids = [];

    foreach ($categories as $name) {
        $url = "https://$domain/wp-json/wp/v2/categories?search=" . urlencode($name);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$username:$password")]
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 200 && ($data = json_decode($response, true)) && !empty($data[0]['id'])) {
            $ids[] = $data[0]['id'];
        } else {
            $created = createCategory($domain, $username, $password, $name);
            if ($created) $ids[] = $created;
        }
    }

    return $ids;
}

function getTagIds($domain, $username, $password, $tags) {
    $ids = [];

    foreach ($tags as $name) {
        $url = "https://$domain/wp-json/wp/v2/tags?search=" . urlencode($name);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$username:$password")]
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 200 && ($data = json_decode($response, true)) && !empty($data[0]['id'])) {
            $ids[] = $data[0]['id'];
        } else {
            $created = createTag($domain, $username, $password, $name);
            if ($created) $ids[] = $created;
        }
    }

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
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-card { border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); background: white; }
        .card-header-custom {
            background: linear-gradient(135deg, #4e73df, #6f42c1);
            color: white;
            padding: 1.5rem;
        }
        .form-label { font-weight: 600; }
        textarea, input { border-radius: 10px; }
        .btn-primary {
            background-color: #4e73df;
            border: none;
            transition: all 0.3s ease;
        }
        .btn-primary:hover { background-color: #375ab6; }
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
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Daftar Domain</label>
                        <textarea name="domain" class="form-control" rows="8" placeholder="domain.com:username:password"><?= htmlspecialchars($_POST['domain'] ?? '') ?></textarea>
                        <small class="text-muted">Pisahkan per baris: <code>domain.com:username:password</code></small>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Judul Post</label>
                        <input name="postTitle" class="form-control mb-3" placeholder="Judul" value="<?= htmlspecialchars($_POST['postTitle'] ?? '') ?>">

                        <label class="form-label">Konten Post</label>
                        <textarea name="postContent" class="form-control mb-2" rows="5" placeholder="Konten..."><?= htmlspecialchars($_POST['postContent'] ?? '') ?></textarea>
                        <small class="text-muted">Gunakan <code>@Domain</code> dan <code>@Judul</code> untuk replace otomatis.</small>

                        <div class="row mt-3 mb-2">
                            <div class="col">
                                <label class="form-label">Kategori</label>
                                <textarea name="categories" class="form-control" rows="3" placeholder="Misal: News, Tutorial"><?= htmlspecialchars($_POST['categories'] ?? '') ?></textarea>
                            </div>
                            <div class="col">
                                <label class="form-label">Tags</label>
                                <textarea name="tags" class="form-control" rows="3" placeholder="Misal: WordPress, Otomatis"><?= htmlspecialchars($_POST['tags'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary mt-2 px-4 py-2" id="submitBtn">Jalankan Post</button>

                        <textarea id="logOutput" class="form-control mt-3" rows="10" placeholder="Log Postingan Akan Di Tampilkan Disini" readonly></textarea>
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
            $('#logOutput').val('Sedang Memproses...');
            $('#submitBtn').text('Memproses...').prop('disabled', true);

            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                success: function(response) {
                    $('#logOutput').val(response);
                    $('#submitBtn').text('Jalankan Post').prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    $('#logOutput').val('Terjadi kesalahan: ' + error);
                    $('#submitBtn').text('Jalankan Post').prop('disabled', false);
                }
            });
        });
    });
</script>
</body>
</html>
