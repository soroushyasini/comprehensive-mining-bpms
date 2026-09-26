# راهنمای استقرار ماژول مناقصات و مزایدات

## پیش‌نیازها

- پشتیبان پایگاه داده `wf_pishro`؛
- نسخهٔ سازگار تابع `shamsi_slash_to_gregorian_date()`؛
- PHP دارای `pdo_mysql`, `mbstring`, `fileinfo` و دسترسی نوشتن به مخزن خصوصی؛
- یک کاربر فعال ProcessMaker با مجوز `authorization:update` برای دریافت مجوز اولیه؛
- جدول موجود `prc_db_mozayedat_monaghesat_copy1` در همان schema؛
- دسترسی SELECT کاربر پایگاه داده EMCORE به جدول قدیمی.

## ۱. کنترل نسخه و پشتیبان

پیش از هر تغییر، تعداد ردیف‌ها، تعداد شناسه‌های یکتا و بازه شناسه جدول قدیمی را مستقیماً از پایگاه داده ثبت کنید:

```sql
SELECT COUNT(*) AS source_rows,
       COUNT(DISTINCT id) AS distinct_ids,
       MIN(id) AS min_id,
       MAX(id) AS max_id
FROM prc_db_mozayedat_monaghesat_copy1;
```

بر اساس snapshot بررسی‌شده هنگام توسعه، انتظار فعلی ۱٬۵۳۹ ردیف با شناسه‌های یکتای ۱ تا ۱۶۱۴ است. محیط اصلی در زمان اجرا مرجع نهایی است؛ وجود فاصله در توالی طبیعی است و نباید با شماره‌گذاری مجدد پنهان شود.

## ۲. اعمال migration

برای استقرار اولیه، migrationهای زیر را به‌ترتیب روی همان schema که جدول‌های EMCORE و `USERS` قرار دارند اجرا کنید:

```text
database/migrations/010_emcore_procurement_notices.sql
database/migrations/011_emcore_procurement_classification.sql
```

اگر migration `010` قبلاً اعمال شده است، فقط `011` را یک‌بار اجرا کنید. این migration ستون `estimated_amount`، سه جدول master data و seed دقیق ۵ گروه، ۱۵ زیرگروه و ۳۲ کالا را اضافه می‌کند و داده‌های فعلی فراخوان یا ضمانت‌نامه ثانویه را تغییر نمی‌دهد.

سپس وجود جدول‌های ماژول، master data و module key را کنترل کنید:

```sql
SELECT module_key, is_active
FROM emcore_modules
WHERE module_key = 'procurement_notices';

SELECT
    (SELECT COUNT(*) FROM emcore_procurement_categories) AS categories,
    (SELECT COUNT(*) FROM emcore_procurement_subcategories) AS subcategories,
    (SELECT COUNT(*) FROM emcore_procurement_products) AS products;
```

پس از seed اولیه، خروجی سه شمارش باید به‌ترتیب `5`، `15` و `32` باشد.

## ۳. مخزن خصوصی فایل

پوشه‌ای خارج از DocumentRoot بسازید و به کاربر سرویس PHP دسترسی نوشتن بدهید. یکی از این دو روش را تنظیم کنید:

```text
EMCORE_PROCUREMENT_STORAGE_ROOT=C:\pmlearning\emcore-private\procurement-notices
EMCORE_PROCUREMENT_MAX_UPLOAD_BYTES=52428800
```

یا کلیدهای `procurement_storage_root` و `procurement_max_upload_bytes` را در فایل untracked `emcore_api/emcore_config.php` قرار دهید. مسیر مخزن را زیر `/emcore_api` یا هر پوشهٔ قابل سرو وب قرار ندهید.

## ۴. dry-run مهاجرت

ابتدا بدون `--commit` اجرا کنید:

```powershell
php tools\import_legacy_procurement_notices.php
```

خروجی JSON را نگه دارید و این موارد را بررسی کنید:

- `source` دقیقاً `prc_db_mozayedat_monaghesat_copy1` باشد؛
- `source_rows` با `COUNT(*)` جدول قدیمی برابر باشد؛
- نمونه‌های `review_samples` برای نوع، وضعیت یا تاریخ نامشخص بررسی شوند؛
- هیچ تبدیل حدسی مبلغ یا تناژ رخ نداده باشد.

`needs_review` به معنی حذف ردیف نیست. backfill رکوردهای قابل ردیابی را با `unknown` یا تاریخ nullable وارد می‌کند و پرچم‌ها را در metadata ممیزی نگه می‌دارد. فقط شناسه نامعتبر یا خطای پایگاه داده باعث skip می‌شود. ابزار ابتدا وجود جدول و تمام ستون‌های مورد انتظار را از `information_schema` کنترل می‌کند و در صورت ناسازگاری schema پیش از هر write متوقف می‌شود.

## ۵. اجرای commit

بلافاصله پیش از commit، فرم و triggerهای قدیمی را در حالت read-only قرار دهید و شمارش مرحلهٔ ۱ را دوباره ثبت کنید. ابزار ردیف‌های جدید را در اجرای دوباره اضافه می‌کند، اما تغییرات بعدی روی ردیفی که قبلاً backfill شده است عمداً روی دادهٔ جدید بازنویسی نمی‌شود؛ بنابراین در پنجرهٔ cutover نباید write هم‌زمان روی سامانه قدیمی وجود داشته باشد.

کاربر actor باید فعال و دارای `procurement_notices:create` باشد:

```powershell
php tools\import_legacy_procurement_notices.php `
  --commit --actor-usr-uid=0123456789abcdef0123456789abcdef
```

اجرای دوباره از نظر ایجاد رکورد تکراری امن است: `legacy_source_id` یکتا است و ردیف‌های قبلاً واردشده در `already_imported` گزارش می‌شوند. این رفتار sync دوطرفه یا overwrite نیست؛ پس جدول قدیمی پس از cutover فقط برای مرجع و rollback نگه‌داری می‌شود.

## ۶. استقرار API و Panel

- `emcore_api/_procurement_storage.php`
- `emcore_api/emcore_procurement_notices.php`
- `emcore_api/emcore_procurement_analytics.php`
- `panels/emcore_procurement_notices_panel.html`
- `panels/emcore_procurement_analytics_panel.html`

پنل CRUD را در WebControl از نوع Panel جایگزین فرم/XCRUD قدیمی کنید و برای گزارش، یک Panel WebControl جداگانه از فایل analytics بسازید. هر دو API و ProcessMaker باید same-origin و دارای نشست مشترک باشند. داشبورد analytics به migration جدیدی نیاز ندارد و مستقیماً از جدول‌های ایجادشده در مرحلهٔ ۲ می‌خواند. فایل Chart.js باید در مسیر عمومی `/lib/js/chart-js/chart.umd.min.js` موجود و برای مرورگر قابل خواندن باشد؛ چون این فایل از نصب فعلی ProcessMaker تأمین می‌شود، آن را از مخزن EMCORE کپی نکنید.

خروجی رکوردهای CRUD از کتابخانه نصب‌شده ProcessMaker در `/lib/xlsx.full.min_2.js` استفاده می‌کند. پیش از انتشار، این مسیر را نیز با نشست عادی مرورگر کنترل کنید؛ فایل کتابخانه از مخزن EMCORE کپی نمی‌شود.

پیش از کپی، کنترل‌های release و lint را اجرا کنید:

```cmd
node tools\check_procurement_notices_release.js
node tools\check_procurement_analytics_release.js
php -n -l emcore_api\emcore_procurement_notices.php
php -n -l emcore_api\emcore_procurement_analytics.php
```

## ۷. کنترل پذیرش

1. کاربر بدون مجوز، پاسخ 403 دریافت کند.
2. کاربر read-only دکمه ایجاد/ویرایش را نبیند و API نوشتن او را رد کند.
3. create/update/delete بدون CSRF با 403 رد شود.
4. تاریخ شمسی معتبر به ستون میلادی صحیح تبدیل شود.
5. ردیف‌های فوری و منقضی با متن و رنگ (نه فقط رنگ) مشخص شوند.
6. جست‌وجو، پنج فیلتر و صفحه‌بندی کار کنند.
7. ویرایش هم‌زمان با نسخه قدیمی پاسخ 409 بدهد.
8. فایل مجاز بارگذاری، دانلود و در download log ثبت شود.
9. فایل قدیمی بدون محتوای منتقل‌شده «فقط مرجع» نشان داده شود و دانلود آن با 409 رد شود.
10. حذف رکورد و فایل نرم و در `emcore_audit_log` ثبت شود.
11. رابط در عرض‌های ۳۲۰، ۷۶۸، ۱۰۲۴ و ۱۴۴۰ پیکسل و با صفحه‌کلید کنترل شود.
12. endpoint تحلیلی بدون نشست 401، بدون مجوز read پاسخ 403 و با کاربر read-only پاسخ موفق بدهد.
13. تعداد کل داشبورد بدون فیلتر با `COUNT(*)` ردیف‌های حذف‌نشده برابر باشد.
14. بازهٔ تاریخ، نوع، وضعیت، منبع، دستگاه، واحد و دسته بر همهٔ نمودارها هم‌زمان اثر بگذارند.
15. نمودار دستگاه اجرایی جمع مناقصه، مزایده و نوع نامشخص را بدون حذف پنهانی نمایش دهد.
16. drill-down دسته‌بندی با صفحه‌کلید تا سطح محصول قابل استفاده باشد و هر نمودار جدول دادهٔ متناظر داشته باشد.
17. مقادیر `unknown`، تاریخ ثبت مفقود، مهلت وارونه و دسته‌بندی مفقود در تب کیفیت داده آشکار باشند.
18. حالت بدون داده پیام صریح نشان دهد و هیچ دادهٔ نمونه‌ای به نمودار تزریق نشود.
19. فایل `/lib/js/chart-js/chart.umd.min.js` با وضعیت 200 بارگیری شود و Console مرورگر خطای `Chart is not defined` نداشته باشد.
20. فیلتر «حداقل مجموع مناقصات و مزایدات» فقط دستگاه‌های زیر آستانه را از نمودار دستگاه اجرایی حذف کند و بر KPI کل یا نمودارهای دیگر اثر نگذارد.
21. در پنل CRUD، انتخاب هم‌زمان چند فایل، progress واقعی بارگذاری و توقف شفاف صف روی نخستین خطا آزمایش شود؛ فایل‌های موفق نباید دوباره ارسال شوند.
22. datepicker مهلت اسناد و پاسخ از شنبه آغاز شود، جمعه و امروز را متمایز کند، جهت chevronهای ماه قبل/بعد در RTL صحیح باشد و با Arrowها، Home، End، PageUp، PageDown، Enter و Escape قابل استفاده باشد.
23. تاریخ `today_gregorian` سرور مبنای روز جاری تقویم باشد و محیط ProcessMaker نتواند سال را به ۷۸۴ تبدیل کند.
24. واحد، نحوه تحویل، واحد پول و زنجیره گروه/زیرگروه/کالا select بومی باشند؛ تغییر والد باید گزینه‌های فرزند را پاک و محدود کند.
25. مبلغ برآوردشده با ارقام فارسی و جداکننده «٬» نمایش داده و با دقت کامل تا ۲۴ رقم ذخیره شود؛ گزینه «دلار» نیز در واحد پول وجود داشته باشد.
26. ویرایش سایر فیلدهای یک رکورد legacy نباید مقدار تاریخی `secondary_guarantee` یا دسته‌بندی خارج از master data را بی‌صدا پاک کند.
27. انتخاب یک تاریخ شمسی در فیلتر «تاریخ ثبت رکورد» فقط رکوردهای همان `registered_on_en` متناظر را نشان دهد؛ تاریخ نامعتبر باید با پاسخ 422 رد شود.
28. ستون‌های «شناسه» و «تاریخ ثبت» در جدول دیده شوند و مرتب‌سازی صعودی/نزولی آن‌ها پیش از صفحه‌بندی انجام شود؛ ترتیب پیش‌فرض `id DESC` باشد.
29. خروجی اکسل پس از اعمال هر ترکیب فیلتر، تمام رکوردهای نتیجه را از همه صفحات در یک فایل `.xlsx` قرار دهد و تعداد ردیف‌های خروجی با `pagination.total` برابر باشد.
30. فایل `/lib/xlsx.full.min_2.js` با وضعیت 200 بارگیری شود؛ وجود writer از نوع `writeFile` یا `write` برای فعال‌شدن خروجی کافی است و نبود helperهای `XLSX.utils` نباید دکمه را غیرفعال نگه دارد. اگر هر دو writer موجود باشند، پنل باید مسیر مرورگری `write` و دانلود `Blob` را انتخاب کند و به `writeFileSync` مخصوص Node نرسد. هنگام ساخت خروجی، دکمه باید موقتاً غیرفعال و پیشرفت جمع‌آوری رکوردها به فارسی نمایش داده شود.

## ۸. تطبیق پس از مهاجرت

```sql
SELECT COUNT(*) AS imported_rows
FROM emcore_procurement_notices
WHERE record_origin = 'legacy';

SELECT COUNT(*) AS unknown_types
FROM emcore_procurement_notices
WHERE record_origin = 'legacy' AND notice_type = 'unknown';

SELECT COUNT(*) AS legacy_file_references
FROM emcore_procurement_files
WHERE record_origin = 'legacy_reference';
```

تعداد رکوردهای legacy مقصد باید با تعداد شناسه‌های یکتای معتبر جدول قدیمی برابر باشد، مگر آنکه dry-run صراحتاً skip را گزارش کرده باشد. جدول قدیمی را در این مرحله حذف یا تغییر ندهید.

## بازگشت

تا پایان پذیرش، فرم و جدول قدیمی را read-only نگه دارید. اگر هنوز هیچ write جدیدی در EMCORE انجام نشده است، می‌توان Panel را به نسخه قبلی برگرداند و API جدید را از مسیر عمومی برداشت. اگر پس از cutover رکورد یا ویرایش جدیدی در EMCORE ثبت شده باشد، ابتدا باید آن تغییرات با جدول قدیمی تطبیق داده شوند؛ فعال‌کردن مستقیم write قدیمی در این وضعیت باعث دو شاخه‌شدن داده می‌شود. جدول‌های جدید را حذف نکنید؛ داده، فایل و ممیزی برای تحلیل و اجرای مجدد حفظ شوند.
