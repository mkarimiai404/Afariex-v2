<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/InaxClient.php';

final class InaxService
{
    private InaxClient $client;

    public function __construct(?InaxClient $client = null)
    {
        $this->client = $client ?? new InaxClient();
    }

    public function getCredit(): array
    {
        return $this->client->credit();
    }

    public function buyTopup(string $mobile, int $amount, string $operator, string $chargeType = 'normal', ?string $orderId = null, ?int $mnp = null): array
    {
        if ($amount <= 0) throw new InvalidArgumentException('INVALID_INAX_AMOUNT');
        $orderId = trim((string)$orderId);
        if ($orderId === '') $orderId = 'AFX-' . strtoupper(bin2hex(random_bytes(12)));
        return $this->client->topupWithOptions($operator, $amount, $mobile, $orderId, $chargeType, $mnp);
    }

    public function getStatus(string $orderId, ?string $transId = null): array
    {
        return $this->client->transStatus($orderId, $transId);
    }

    public function translateErrorCode(int|string $code, string $defaultMsg = ''): string
    {
        return match ((int)$code) {
            1 => 'عملیات با موفقیت انجام شد.',
            -11 => 'پارامترهای سرویس کامل نیست.',
            -12 => 'شماره موبایل یا اپراتور صحیح نیست.',
            -18 => 'این شماره همراه اول دائمی نیست.',
            -22 => 'احراز هویت سرویس خرید شارژ ناموفق بود.',
            -33 => 'اعتبار پنل خرید شارژ کافی نیست.',
            -44 => 'مبلغ شارژ برای این اپراتور مجاز نیست.',
            -55 => 'تراکنش خرید شارژ پیدا نشد.',
            -66 => 'اپراتور ایرانسل موقتاً در دسترس نیست.',
            -67 => 'اپراتور همراه اول موقتاً در دسترس نیست.',
            -77 => 'این درخواست قبلاً ارسال شده است.',
            -91 => 'خطای موقت سرویس خرید شارژ رخ داد.',
            -200 => 'تراکنش در انتظار پاسخ نهایی سرویس است.',
            default => trim($defaultMsg) !== '' ? trim($defaultMsg) : 'خرید شارژ انجام نشد.',
        };
    }
}
