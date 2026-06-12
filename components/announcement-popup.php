<?php
/** @var array $homepageAnnouncement */
/** @var string $announcementPopupHtml */
/** @var string $announcementPopupFreq */
$announcementId = (int)($homepageAnnouncement['id'] ?? 0);
$shouldMarkRead = ($announcementPopupFreq ?? '') !== ANNOUNCEMENT_FREQ_ALWAYS;
?>
<div id="siteAnnouncementModal" class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 px-4 py-6">
    <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
        <div class="flex-1 overflow-y-auto p-6 announcement-popup-body text-sm text-gray-700 dark:text-gray-200">
            <?= $announcementPopupHtml ?>
        </div>
        <div class="border-t border-gray-100 px-6 py-4 flex justify-end dark:border-gray-700">
            <button type="button" id="siteAnnouncementCloseBtn"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                我知道了
            </button>
        </div>
    </div>
</div>
<style>
.announcement-popup-body img { max-width: 100%; height: auto; }
.announcement-popup-body a { color: #2563eb; text-decoration: underline; }
</style>
<script>
(() => {
    const modal = document.getElementById('siteAnnouncementModal');
    const btn = document.getElementById('siteAnnouncementCloseBtn');
    if (!modal || !btn) return;

    const announcementId = <?= (int)$announcementId ?>;
    const shouldMarkRead = <?= $shouldMarkRead ? 'true' : 'false' ?>;

    async function closeModal() {
        if (shouldMarkRead && announcementId > 0) {
            try {
                const body = new URLSearchParams();
                body.set('announcement_id', String(announcementId));
                await fetch('api/announcement_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin',
                });
            } catch (e) {}
        }
        modal.remove();
    }

    btn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
})();
</script>
