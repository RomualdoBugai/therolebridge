<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
  <meta name="author" content="<?= htmlspecialchars($pageAuthor) ?>">
  <meta name="website" content="<?= htmlspecialchars($pageUrl) ?>">
  <meta name="email" content="<?= htmlspecialchars($pageEmail) ?>">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">

  <!-- favicon -->
  <link rel="shortcut icon" href="assets/images/<?= $logoFavicon ?>" type="image/x-icon">

  <!-- Css -->
  <link href="assets/libs/tiny-slider/tiny-slider.css" rel="stylesheet">
  <link href="assets/libs/choices.js/public/assets/styles/choices.min.css" rel="stylesheet">
  <!-- Main Css -->
  <link href="assets/libs/remixicon/fonts/remixicon.css" rel="stylesheet" type="text/css" />
  <link rel="stylesheet" href="assets/css/tailwind.css">

  <?php if ($customCss): ?>
    <link rel="stylesheet" href="assets/css/<?= $customCss ?>">
  <?php endif; ?>
</head>