<?php

declare(strict_types=1);

require_once __DIR__ . '/assets.php';

$emailResetUseCdn = !empty($emailResetUseCdn);
?>
<link rel="stylesheet" href="<?= htmlspecialchars(emailResetStylesheetUrl($emailResetUseCdn), ENT_QUOTES, 'UTF-8') ?>">
<script>
(function () {
    if (localStorage.theme === 'dark') {
        document.documentElement.classList.add('dark');
    }
})();
</script>
