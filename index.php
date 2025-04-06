<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postTitle = $_POST['postTitle'] ?? '';
    $postContent = $_POST['postContent'] ?? '';
    $domainInput = $_POST['domain'] ?? '';

    if (empty($postTitle) || empty($postContent)) {
        $responseMessage = 'Semua kolom harus diisi.';
        $responseType = 'danger';
    } elseif (empty($domainInput)) {
        $responseMessage = 'Harap masukkan domain terlebih dahulu.';
        $responseType = 'danger';
    } else {
        $domains = explode("\n", $domainInput);
        $domains = array_map('trim', $domains);
        $domains = array_filter($domains);

        $successDomains = [];
        $failedDomains = [];

        foreach ($domains as $domainData) {
            list($domain, $username, $password) = explode(":", $domainData);

            if ($domain && $username && $password) {
                $result = createPostForDomain($domain, $username, $password, $postTitle, $postContent);
                if ($result === true) {
                    $successDomains[] = $domain;
                } else {
                    $failedDomains[] = "$domain: $result";
                }
            } else {
                $failedDomains[] = "Format domain salah: $domainData";
            }
        }

        if (!empty($successDomains)) {
            $successMessage = "Post berhasil dibuat di: " . implode(', ', $successDomains) . "!";
        }

        if (!empty($failedDomains)) {
            $failedMessage = "Gagal membuat post di: " . implode(', ', $failedDomains) . ".";
        }
    }
}

function createPostForDomain($domain, $username, $password, $postTitle, $postContent) {
    $postContent = str_replace('@Domain', $domain, $postContent);
    $postContent = str_replace('@Judul', $postTitle, $postContent);
    $apiUrl = "https://$domain/wp-json/wp/v2/posts";
    $headers = [
        'Authorization: Basic ' . base64_encode($username . ':' . $password),
        'Content-Type: application/json'
    ];

    $postData = [
        'title' => $postTitle,
        'content' => $postContent,
        'status' => 'draft'
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return 'Error: ' . curl_error($ch);
    }

    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseCode === 201) {
        return true;
    }

    return 'Failed to create post. HTTP Status: ' . $responseCode;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wp Poster</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="https://s.w.org/favicon.ico" type="image/x-icon">

    <style>
        body {
            font-family: Arial, sans-serif;
        }
        .container {
            margin-top: 30px;
        }
        .alert {
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="domain" class="form-label">Website</label>
                            <textarea class="form-control" id="domain" name="domain" rows="5" placeholder="domain:username:password, tekan enter untuk menambah domain"><?= htmlspecialchars($domainInput ?? '') ?></textarea>
                        </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-body">
                    <h3>Buat Postingan</h3>
                    <div class="mb-3">
                        <label for="postTitle" class="form-label">Judul</label>
                        <input type="text" class="form-control" id="postTitle" name="postTitle" value="<?= htmlspecialchars($postTitle ?? '') ?>" placeholder="Enter post title">
                    </div>
                    <div class="mb-3">
                        <label for="postContent" class="form-label">Konten</label>
                        <textarea class="form-control" id="postContent" name="postContent" rows="5" placeholder="Enter post content"><?= htmlspecialchars($postContent ?? '') ?></textarea>
                        <small class="text-muted">Gunakan @Domain dan @Judul untuk replace domain dan judul</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Jalankan Post</button>
                    <?php if (isset($successMessage)): ?>
                        <div class="mt-3 alert alert-success">
                            <?= htmlspecialchars($successMessage) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($failedMessage)): ?>
                        <div class="mt-3 alert alert-danger">
                            <?= htmlspecialchars($failedMessage) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>

</body>
</html>
