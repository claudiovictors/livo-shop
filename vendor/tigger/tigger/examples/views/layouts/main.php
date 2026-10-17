<!DOCTYPE html>
<html lang="pt-AO">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($shop) ?></title>
  <link rel="stylesheet" href="/public/css/style.css">
</head>
<body>
  <?= $content ?>
  <script nonce="<?= $this->nonce() ?>">console.log('inline scripts need the nonce');</script>
</body>
</html>
