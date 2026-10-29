# مرجع پروژه آفاریکس (یادداشت عامل)

یادداشت کاری برای ادامهٔ توسعه. فایل محصولی نیست؛ در صورت تمایل می‌توان حذفش کرد.

## ماهیت پروژه

- اپلیکیشن موبایل صرافی/خدمات مالی «آفاریکس» با Expo + React Native.
- مسیر اصلی: `expo-router/entry` (فایل‌محور)، نسخهٔ اپ `1.0.1`، پکیج اندروید `com.mahyaasmari1.afariex`.
- بک‌اند: PHP خالص (بدون فریم‌ورک) در پوشهٔ `API/`، همراه با پنل ادمین PHP در `admin_panel/`.
- دیتابیس: MySQL/MariaDB با مایگریشن‌های SQL در `database/migrations/`.
- زبان و جهت رابط: فارسی، RTL. واحد پول: تومان.

## استک و تنظیمات

- Expo SDK ~54، React Native 0.81.5، React 19.1، TypeScript 5.9 (`strict: true`).
- مسیر میان‌بر: `@/*` به ریشهٔ پروژه.
- فونت سراسری Vazirmatn (۹ وزن) که در `lib/apply-global-font.ts` روی
  `Text.defaultProps` و `TextInput.defaultProps` اعمال می‌شود.
- تم: `lib/theme.ts` → `appColors` (پس‌زمینه `#00090A`، سطح `#043D41`، اصلی
  `#06EFF4`، متن `#FFFFFF`). تم قالب پیش‌فرض Expo در `constants/theme.ts` بی‌استفاده است.
- کتابخانه‌های کلیدی: expo-router، expo-secure-store، expo-local-authentication،
  expo-print، expo-sharing، expo-clipboard، expo-linear-gradient، expo-image-picker،
  react-native-toast-message، @react-native-picker/picker.
- `newArchEnabled: true` و `experiments.reactCompiler: true` در `app.json`.

## تنظیمات محیط و API

- `EXPO_PUBLIC_API_BASE_URL` نقطهٔ اتصال اپ است (در `.env.local` و در ورک‌فلوهای CI
  مقدار `https://afariex.ir/API/`).
- `lib/api.ts`: `fetchJson` با `AbortController`، تایم‌اوت پیش‌فرض ۱۲ ثانیه و
  استثنای ۳۵ ثانیه برای `topup.php` و `topup-create.php`. خطاها به
  `ApiResponseError` (کد پاسخ + پیام ایمن) یا خطاهای شبکه‌ای
  (`ApiTimeoutError`, `ApiHtmlResponseError`, `ApiInvalidResponseError`, `ApiNetworkError`) نگاشت می‌شوند.
- توکن API فقط در حافظهٔ پروسه نگه‌داری می‌شود (`lib/auth-runtime.ts`)؛
  `AsyncStorage` هرگز برای نشست لاگین خوانده نمی‌شود و در هر بوت اجرا پاک می‌شود.
- سرورهای PHP با `admin_panel/config.php` پیکربندی می‌شوند: بارگذاری `.env.local` و
  (فقط خارج از محیط محلی) `.env.production.local`، تعریف `DB_*`، منطقهٔ زمانی
  `Asia/Tehran`، و توابع سطح‌بالای `db()`، `table_exists()`، `e()`.
- متغیرهای سمت سرور: `APP_ENV`, `SMS_PROVIDER`, `MELIPAYAMAK_*`, `OTP_DEV_RETURN_CODE`,
  `OTP_TOKEN_SECRET`, `OTP_RATE_LIMIT_SECRET`, `DB_*`, `INAX_USERNAME`, `INAX_PASSWORD`.

## احراز هویت و امنیت

- `lib/auth-context.tsx` نگه‌دارندهٔ نشست: `userId`, `userToken`, `userName`, `userMobile`,
  `userBalance`, `isSubuser`, `phoneVerified`, `kycStatus`.
- `app/_layout.tsx`: بارگذاری فونت، `AuthRouteBoundary` با سپر مسیر تا زمان قطعی‌شدن وضعیت،
  و `PUBLIC_AUTH_PATHS = { '/', '/login', '/register' }`؛ سایر مسیرها کاربر
  وارد‌نشده را به `/login` می‌فرستند.
- توکن همیشه در بدنهٔ درخواست ارسال می‌شود (`api_token` / `token` / `user_token`) و اپ
  خودش هم با `authExpiryHandler` روی ۴۰۱ (`AUTHENTICATION_REQUIRED|AUTHENTICATION_FAILED`) خروج می‌زند.
- ورود با اثر انگشت: `lib/biometric-auth.ts` با `expo-secure-store` (کلیدهای
  `afariex_biometric_session_v1` و `afariex_biometric_enabled_v1`، `requireAuthentication: true`).
- OTP: `API/includes/otp.php` (هدف‌های `registration`, `change_password`, `change_pin`)،
  هش توکن با HMAC، محدودیت نرخ بر پایهٔ IP، و ارسال پیامک با ملی‌پیامک
  (`API/includes/sms_provider.php`، الگوی `MELIPAYAMAK_BODY_ID = 509216`).
- رمز عبور با `password_verify`؛ پین با `pin_hash`.
- کاربر زیرمجموعه (`subcustomer`): با `parent_id` (یا `referred_by_user_id` در اسکیمای قدیمی)
  شناسایی می‌شود؛ تا تأیید تلفن و احراز هویت، خدمات مالی قفل است (`subcustomer_require_verified`).
- سطح‌های احراز هویت: `bronze` → `silver` → `gold` با سقف روزانهٔ
  `5,000,000` / `100,000,000` / `15,000,000` تومان (در `config.php`).

## نقشهٔ صفحه‌ها (app/)

| مسیر | نقش |
| --- | --- |
| `index.tsx` | صفحهٔ خوش‌آمد، دکمه‌های ورود/ثبت‌نام |
| `login.tsx`, `register.tsx` | ورود (با اثر انگشت) و ثبت‌نام سهمرحله‌ای با OTP |
| `dashboard.tsx` | داشبورد: موجودی، بدهی، شبکهٔ خدمات، نوار پایین |
| `add-balance.tsx` | اعلام واریز با رسید |
| `withdraw.tsx`, `withdrawal-receipt.tsx` | برداشت وجه + رسید/PDF |
| `add-remittance.tsx`, `remittance-receipt.tsx` | ثبت حواله بین‌المللی + رسید/PDF |
| `topup.tsx` | خرید شارژ موبایل (Inax) |
| `transactions-history.tsx` | تاریخچهٔ تراکنش‌ها با فیلتر |
| `orders.tsx` | سفارش‌ها و آخرین فعالیت |
| `notifications.tsx` | اعلان‌ها |
| `profile.tsx`, `my-profile.tsx` | منوی پروفایل و جزئیات کاربر |
| `access-level.tsx`, `verification-upgrade.tsx`, `phone-verification.tsx` | سطح دسترسی و ارتقای احراز هویت |
| `customers.tsx` | مشتریان/زیرمجموعه‌ها و کد معرف |
| `change-password.tsx`, `change-pin.tsx` | تغییر رمز و پین (با OTP) |
| `support.tsx`, `about.tsx`, `partnership.tsx` | پشتیبانی (واتس‌اپ ۹۸۹۱۹۴۹۹۸۰۲۰)، درباره ما، همکاری |

## قرارداد API (پوشهٔ API/)

- احراز هویت/پروفایل: `login.php`, `register.php`, `profile.php`, `dashboard.php`,
  `change-password.php`, `change-pin.php`, `phone-verification.php`, `auth/request-otp.php`, `auth/verify-otp.php`.
- کیف پول: `add-balance.php`, `withdraw.php`, `withdrawal-receipt.php`, `transactions-history.php`,
  `service-fee-preview.php`, `access-level.php`, `referrals.php`, `orders.php`, `get_notifications.php`.
- حواله: `add_remittance.php`, `get_remittance_destinations.php`, `get_agencies.php`,
  `get_exchange_rates.php`, `get_active_exchange_rate.php`, همراه با ثبات‌های
  `includes/remittance_currencies.php` و `includes/remittance_destination_countries.php`.
- شارژ: `topup.php` (و `topup-create.php` که فقط آن را require می‌کند)، `topup-status.php`,
  `topup-operators.php`, `topup-credit.php`, `internet-package*.php`، هستهٔ مشترک در
  `lib/topup_common.php` و کلاینت Inax در `lib/InaxClient.php` + `services/InaxService.php`.
- احراز هویت زیرمجموعه: `create-subcustomer.php`, `verification-request.php`.
- عملیات مالی حساس از `API/includes/wallet_service.php` استفاده می‌کنند:
  `wallet_debit` / `wallet_credit` با تراکنش، `SELECT ... FOR UPDATE` و
  در نظر گرفتن `overdraft_limit`.
- پاسخ‌ها JSON با `success`, `code`, `message` هستند؛ کدهای خطا در سمت اپ به متن
  فارسی نگاشت می‌شوند (نمونه در `app/register.tsx` → `codeMessage`).

## دیتابیس

- جدول‌های اصلی: `users` (با `balance`, `overdraft_limit`, `api_token`, `pin_hash`, `parent_id`,
  `referral_code`, `referred_by_user_id`, ستون‌های رضایت‌نامه)، `transactions`
  (با `balance_before/after`, `idempotency_key`, فیلدهای شارژ و رسید)، `remittances`,
  `notifications`, `agencies`, `exchange_rates`, `user_verification_levels`,
  `verification_upgrade_requests`, `phone_verification_codes`, `user_debts`,
  `service_fees`, `user_fee_shares`, `mobile_recharges`.
- مایگریشن‌ها شماره‌گذاری‌شده‌اند؛ نسخهٔ `*_phpmyadmin.sql` برای اجرای دستی است.
- `API/includes/subcustomer.php` ستون‌های موجود را با `SHOW COLUMNS` تشخیص می‌دهد،
  پس کد با اسکیماهای قدیمی و جدید سازگار است.
- `local-backups/` نسخه‌های پشتیبان SQL محیط محلی را نگه می‌دارد.

## پنل ادمین (admin_panel/)

- PHP سمت سرور با نشست ادمین، `layout.php` (چیدمان و منو) و `config.php`.
- صفحات: داشبورد، نمایندگی‌ها، نرخ ارز، کاربران، حواله‌ها، برداشت‌ها، رسیدهای واریز،
  بدهی کاربران، احراز هویت (`verifications.php` + `verification_service.php`)،
  کارمزد خدمات (`service_fees.php`, `service_fee_service.php`, `service_fee_reports.php`),
  سهم کارمزد کاربران (`user_fee_shares.php`)، شارژ (`topups.php`)، صورت‌حساب کاربر
  (`user_statement.php`, `statement_export.php`)، گزارش فعالیت (`activity_logs.php`).
- فایل‌های احراز هویت در `private_verifications/` با `.htaccess` محافظت می‌شوند.
- `admin_panel.zip` نسخهٔ آرشیوی پنل است.

## تست‌ها و CI

- تست‌های Node: `tests/money-input.test.mjs`, `tests/remittance-whatsapp.test.mjs`,
  `scripts/auth-handoff.test.mjs` (اسکریپت `npm run test:auth`).
- تست‌های PHP در `tests/` و `scripts/test-*.php` (شامل گردش برداشت، احراز هویت،
  محدودیت طلا، و بررسی شبکهٔ ملی‌پیامک).
- ورک‌فلوها: `.github/workflows/android-apk.yml` و `android.yml`؛ هر دو APK ریلیز
  امضاشده می‌سازند و در صورت نبود Secrets امضا، بیلد را رد می‌کنند.
- لینت: `npm run lint` (ESLint 9 + eslint-config-expo).

## نکات و بدهی‌های فنی مشاهده‌شده

- `API/dashboard.php` بعد از هر `json_response` که خودش `exit` می‌کند، یک پاسخ
  غیرقابل‌دسترس دارد؛ تمیزکاری بی‌خطر است.
- `app/change-pin.tsx` و `app/change-password.tsx` در یک خط فشرده نوشته شده‌اند و
  خواندن/ویرایششان دشوار است.
- به‌جای یک لایهٔ مشترک، هر صفحه جداگانه `toPersianNum`/`formatPrice` و فراخوانی
  `dashboard.php` برای موجودی دارد؛ کاندید مناسب برای استخراج به `lib/`.
- `app-bottom-nav.tsx` کلید فعال را از مسیر استنتاج می‌کند و روی صفحه‌های دیگر
  (مثل `profile`) ممکن است آیتم اشتباه برجسته شود.
- `unreadCount` در داشبورد مقدار ثابت `1` است.
- تعداد اعلان‌های خوانده‌نشده واقعی از `get_notifications.php` گرفته نمی‌شود.
