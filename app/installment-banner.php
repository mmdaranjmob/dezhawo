<?php
declare(strict_types=1);

$installmentImage = 'https://www.mydigipay.com/mag/wp-content/uploads/2025/08/digipay-merchant-website-banner.jpg';
foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
    $candidate = 'assets/digipay-installments.' . $extension;
    if (is_file(dirname(__DIR__) . '/' . $candidate)) {
        $installmentImage = $candidate;
        break;
    }
}
?>
<section class="installment-section container" id="installmentBanner" aria-labelledby="installmentTitle" hidden>
  <div class="installment-card <?= $installmentImage ? 'installment-card--image' : 'installment-card--text' ?>">
    <span class="installment-label"><span data-icon="spark"></span>یک مزیت برای خرید تو</span>
    <?php if ($installmentImage): ?>
      <img class="installment-image" src="<?= htmlspecialchars($installmentImage, ENT_QUOTES, 'UTF-8') ?>" width="1920" height="1080" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="دیجی‌پی؛ لذت خرید قسطی با اعتبار دیجی‌پی" onerror="this.closest('.installment-card').classList.replace('installment-card--image','installment-card--text');this.remove()">
    <?php endif; ?>
    <div class="installment-copy">
      <span class="installment-brand">دیجی‌پی</span>
      <h2 id="installmentTitle">لذت خرید قسطی<br>با اعتبار دیجی‌پی</h2>
      <p>انتخابی که دوست داری، با فرصت پرداخت راحت‌تر.</p>
    </div>
    <div class="installment-actions">
      <div><strong>یک انتخاب راحت‌تر برای خرید از دژاوو.</strong><p>محصولت را انتخاب کن و در مرحلهٔ پرداخت، دیجی‌پی را بزن. امکان خرید اعتباری به اعتبار و شرایط حساب دیجی‌پی تو بستگی دارد.</p></div>
      <a class="btn installment-button" href="#catalog">شروع خرید قسطی<span data-icon="arrow"></span></a>
    </div>
  </div>
</section>
