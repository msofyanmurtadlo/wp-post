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
            $chunkSize = 5; // Reduced chunk size for better handling
            $domainChunks = array_chunk($domains, $chunkSize);

            foreach ($domainChunks as $chunk) {
                $domainResults = createPostsForDomains($chunk, $postTitle, $postContent, $postexcerpt, $categories, $tags, $keywords, $imageIds);
                foreach ($domainResults as $domain => $result) {
                    if ($result === true) {
                        echo '<span class="success">' . $domain . ' berhasil</span>' . "\n";
                        flush();
                    } else {
                        echo '<span class="error">' . $domain . ' gagal (' . $result . ')</span>' . "\n";
                        flush();
                    }
                }
                sleep(1); // Add small delay between chunks
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
        CURLOPT_TIMEOUT => 30,
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

function createPostsForDomains($domains, $postTitle, $postContent, $postexcerpt, $categories, $tags, $keywords, $featuredImageIds)
{
    $domainResults = [];

    foreach ($domains as $domainData) {
        $parts = explode(':', $domainData);
        if (count($parts) !== 3) {
            $domainResults[$domainData] = "Format tidak valid";
            continue;
        }

        list($domain, $username, $password) = $parts;

        try {
            // Get or create categories with retry mechanism
            $catIds = [];
            foreach ($categories as $category) {
                $catId = getOrCreateCategory($domain, $username, $password, $category);
                if ($catId) {
                    $catIds[] = $catId;
                }
            }

            if (empty($catIds)) {
                $domainResults[$domain] = "Gagal membuat/mendapatkan kategori";
                continue;
            }

            // Get or create tags with retry mechanism
            $tagIds = [];
            foreach ($tags as $tag) {
                $tagId = getOrCreateTag($domain, $username, $password, $tag);
                if ($tagId) {
                    $tagIds[] = $tagId;
                }
            }

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
                'status' => 'draft',
                'categories' => $catIds,
                'tags' => $tagIds,
            ];

            if (isset($featuredImageIds[$domain]['id'])) {
                $postData['featured_media'] = $featuredImageIds[$domain]['id'];
            }

            $response = wpPost("https://$domain/wp-json/wp/v2/posts", $username, $password, $postData);

            if ($response && isset($response['id'])) {
                $domainResults[$domain] = true;
            } else {
                $error = isset($response['message']) ? $response['message'] : 'Unknown error';
                $domainResults[$domain] = "Gagal membuat post: " . $error;
            }
        } catch (Exception $e) {
            $domainResults[$domain] = "Exception: " . $e->getMessage();
        }
    }

    return $domainResults;
}

function getOrCreateCategory($domain, $username, $password, $name, $retry = 3)
{
    $existing = findExistingTerm($domain, $username, $password, 'categories', $name);
    if ($existing) {
        return $existing;
    }

    for ($i = 0; $i < $retry; $i++) {
        $created = createCategory($domain, $username, $password, $name);
        if ($created) {
            return $created;
        }
        sleep(1); // Wait before retry
    }

    return null;
}

function getOrCreateTag($domain, $username, $password, $name, $retry = 3)
{
    $existing = findExistingTerm($domain, $username, $password, 'tags', $name);
    if ($existing) {
        return $existing;
    }

    for ($i = 0; $i < $retry; $i++) {
        $created = createTag($domain, $username, $password, $name);
        if ($created) {
            return $created;
        }
        sleep(1); // Wait before retry
    }

    return null;
}

function findExistingTerm($domain, $username, $password, $type, $name)
{
    $url = "https://$domain/wp-json/wp/v2/$type?search=" . urlencode($name);
    $response = wpGet($url, $username, $password);

    if ($response && is_array($response) && !empty($response)) {
        foreach ($response as $term) {
            if (strtolower($term['name']) === strtolower($name)) {
                return $term['id'];
            }
        }
    }

    return null;
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

function wpGet($url, $username, $password)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode("$username:$password"),
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("cURL error on wpGet: $error");
        return null;
    }

    return json_decode($response, true);
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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("cURL error on wpPost: $error");
        return ['message' => $error];
    }

    return json_decode($response, true);
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
        body {
            background-color: #f4f4f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-card {
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            background: #ffffff;
        }

        .card-header-custom {
            background: linear-gradient(135deg, #4e73df, #6f42c1);
            color: white;
            padding: 1.5rem;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
        }

        textarea,
        input {
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background-color: #4e73df;
            border: none;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background-color: #375ab6;
        }

        .card-footer {
            background-color: #f8f9fc;
            border-bottom-left-radius: 15px;
            border-bottom-right-radius: 15px;
            padding: 1rem;
            text-align: center;
        }

        #logOutput {
            background-color: #f1f1f1;
            color: #000;
            font-family: monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .error {
            color: red;
        }

        .success {
            color: green;
        }

        .info {
            color: blue;
        }
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
                <form id="postForm" method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-4 ">
                            <div class="mb-3">
                                <label class="form-label">Daftar Website</label>
                                <textarea name="domain" class="form-control" rows="8" placeholder="domain.com:username:password"><?= htmlspecialchars($_POST['domain'] ?? '') ?></textarea>
                                <small class="text-muted">Pisahkan per baris: <code>domain.com:username:password</code></small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Daftar Keyword</label>
                                <textarea name="keywords" class="form-control" rows="5" placeholder="Masukkan keywords (1 per baris)"><?= htmlspecialchars($_POST['keywords'] ?? '') ?></textarea>
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
                            <small class="text-muted">Gunakan <code>@Domain</code> , <code>@Judul</code> dan <code>@Gambar</code> untuk replace otomatis.</small>
                            <div class="row mt-3 mb-3">
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
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Gambar Featured</label>
                                <input type="file" name="featured_image" class="form-control">
                                <small class="text-muted">Pilih gambar untuk dijadikan Featured Image.</small>
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
                var formData = new FormData(this);
                $('#logOutput').text('Sedang Memproses...');
                $('#submitBtn').text('Memproses...').prop('disabled', true);

                $.ajax({
                    url: '',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
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