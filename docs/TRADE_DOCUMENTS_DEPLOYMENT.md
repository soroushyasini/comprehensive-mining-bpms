# Trade documents deployment runbook

این runbook برای نصب فعلی ProcessMaker روی ویندوز نوشته شده است و ماژول `trade_documents` را پوشش می‌دهد.

## مسیرها

Command Prompt را با دسترسی عملیاتی مناسب باز کنید و متغیرهای زیر را تنظیم کنید:

```bat
set "EMCORE_RELEASE=C:\pmlearning\comprehensive-mining-bpms"
set "MYSQL_BIN=C:\pmlearning\mysql\bin"
set "PM_WEBROOT=C:\pmlearning\bpms\workflow\public_html"
set "PM_EMCORE_API=%PM_WEBROOT%\emcore_api"
set "PM_DATABASE=wf_pishro"
set "EMCORE_TRADE_STORAGE=C:\pmlearning\emcore-private\trade-documents"
```

پیش از اجرای migration، مقدار `PM_DATABASE` را با `dbname` در فایل `%PM_EMCORE_API%\emcore_config.php` تطبیق دهید:

```bat
findstr /N /C:"dbname=" "%PM_EMCORE_API%\emcore_config.php"
```

از پایگاه داده، API زنده و فایل تنظیمات نسخهٔ پشتیبان بگیرید. از زمان شروع بهره‌برداری، پوشهٔ `%EMCORE_TRADE_STORAGE%` نیز باید همراه پایگاه داده پشتیبان‌گیری شود.

## کنترل اولیهٔ release

```bat
cd /D "%EMCORE_RELEASE%"
git pull --ff-only
git status --short
php -n -l "%EMCORE_RELEASE%\emcore_api\_trade_storage.php"
php -n -l "%EMCORE_RELEASE%\emcore_api\emcore_trade_documents.php"
```

اگر Node.js روی سرور در دسترس است، بررسی انتشار مخزن را هم اجرا کنید:

```bat
node "%EMCORE_RELEASE%\tools\check_trade_documents_release.js"
```

با worktree تغییرکرده یا فایل ناشناخته deploy نکنید. تنظیمات local و ignored که متعلق به اپراتور است، تغییر release به شمار نمی‌آید.

## آماده‌سازی فضای خصوصی فایل‌ها

پوشهٔ فایل‌ها باید خارج از `%PM_WEBROOT%` باشد. آن را داخل `public_html`، `emcore_api` یا مسیر قابل دانلود وب نسازید.

```bat
if not exist "%EMCORE_TRADE_STORAGE%" mkdir "%EMCORE_TRADE_STORAGE%"
if not exist "%EMCORE_TRADE_STORAGE%" exit /B 1
icacls "%EMCORE_TRADE_STORAGE%"
```

حسابی که PHP/وب‌سرور با آن اجرا می‌شود باید روی این پوشه دسترسی Modify داشته باشد. دسترسی را فقط به همان حساب سرویس بدهید و از مجوزهای عمومی مانند `Everyone:F` استفاده نکنید. این دستور را پس از جایگزین‌کردن نام واقعی حساب سرویس اجرا کنید:

```bat
icacls "%EMCORE_TRADE_STORAGE%" /grant "PROCESSMAKER_SERVICE_ACCOUNT:(OI)(CI)M"
```

وجود افزونهٔ Fileinfo و قابل نوشتن بودن مسیر را با PHP نصب‌شده کنترل کنید:

```bat
php -r "if (!extension_loaded('fileinfo')) { fwrite(STDERR, 'fileinfo is not loaded'.PHP_EOL); exit(1); } echo 'fileinfo OK'.PHP_EOL;"
php -r "$p='%EMCORE_TRADE_STORAGE%'; if (!is_dir($p) || !is_writable($p)) { fwrite(STDERR, 'storage is unavailable'.PHP_EOL); exit(1); } echo realpath($p), PHP_EOL;"
```

بررسی `is_writable` باید در نهایت با همان حساب سرویس وب انجام شود. موفق‌بودن دستور تحت حساب اپراتور به‌تنهایی کافی نیست.

حدهای `upload_max_filesize` و `post_max_size` در `php.ini` فعال ProcessMaker باید حداقل برابر سقف انتخاب‌شده باشند:

```bat
php -i | findstr /I "Loaded Configuration File upload_max_filesize post_max_size"
```

## تنظیم API زنده

این دو کلید را پیش از `];` پایانی فایل `%PM_EMCORE_API%\emcore_config.php` اضافه کنید:

```php
'trade_storage_root' => 'C:\\pmlearning\\emcore-private\\trade-documents',
'trade_max_upload_bytes' => 52428800,
```

فایل `emcore_config.php` حاوی تنظیمات محیط است و نباید با `emcore_config.example.php` مخزن جایگزین شود. مقدار پیش‌فرض بالا سقف هر فایل را روی ۵۰ مگابایت می‌گذارد.

پس از ویرایش، نحو فایل تنظیمات را کنترل کنید:

```bat
php -n -l "%PM_EMCORE_API%\emcore_config.php"
```

## کنترل کانتر پیش از migration

مقادیر اولیهٔ migration بر اساس آخرین اطلاعات موجود چنین‌اند:

| شرکت | پیشوند | شمارهٔ بعدی |
|---|---|---:|
| امیدکو | `EMDEX` | ۲۱ |
| امیدکو متال | `EMDMET` | ۴۴ |

با مسئول دفتر تأیید کنید که پس از تهیهٔ این release، PI دیگری خارج از سامانه صادر نشده است. `next_sequence` باید شمارهٔ بعدی قابل صدور باشد، نه آخرین شمارهٔ مصرف‌شده.

## اجرای migration

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 --show-warnings "%PM_DATABASE%" < "%EMCORE_RELEASE%\database\migrations\007_emcore_trade_documents.sql"
```

Migration قابل اجرای دوباره است. جدول‌های پرونده، سه سند اصلی، نسخه‌ها، قالب‌ها، پیوست‌ها و لاگ دانلود را می‌سازد؛ دو شرکت را ثبت می‌کند؛ ماژول `trade_documents` را به ماتریس مجوز می‌افزاید و به مدیران فعال authorization دسترسی اولیه می‌دهد. اجرای دوباره، کانتر موجود را بازنشانی نمی‌کند.

نتیجه را بررسی کنید:

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 "%PM_DATABASE%" -e "SELECT module_key,name_fa,is_active FROM emcore_modules WHERE module_key='trade_documents'; SELECT issuer_key,name_fa,code_prefix,next_sequence,is_active FROM emcore_trade_issuers ORDER BY id; SHOW TABLES LIKE 'emcore_trade_%'; SELECT usr_uid,can_create,can_read,can_update,can_delete FROM emcore_user_permissions WHERE module_key='trade_documents';"
```

ابتدا مطمئن شوید هنوز هیچ پرونده‌ای ساخته نشده است:

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 "%PM_DATABASE%" -e "SELECT COUNT(*) AS case_count FROM emcore_trade_cases;"
```

اگر `case_count` صفر بود و تطبیق عملیاتی عدد دیگری نشان داد، مقادیر درست را در دستور زیر قرار دهید و سپس اجرا کنید:

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 "%PM_DATABASE%" -e "UPDATE emcore_trade_issuers SET next_sequence=21 WHERE issuer_key='emidco'; UPDATE emcore_trade_issuers SET next_sequence=44 WHERE issuer_key='emidco_metal'; SELECT issuer_key,code_prefix,next_sequence FROM emcore_trade_issuers ORDER BY id;"
```

اعداد `21` و `44` در این دستور نمونهٔ مقادیر فعلی‌اند. پس از نخستین رزرو، کانتر را عقب نبرید و شمارهٔ حذف‌شده را دوباره مصرف نکنید.

## فعال‌سازی ثبت سوابق قبلی

پس از migration اصلی، migration سوابق قبلی را اجرا کنید. در سروری که migration شمارهٔ ۰۰۷ قبلاً اجرا شده است، فقط همین مرحلهٔ جدید لازم است:

~~~bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 --show-warnings "%PM_DATABASE%" < "%EMCORE_RELEASE%\database\migrations\008_emcore_trade_legacy_records.sql"
~~~

این migration قابل اجرای دوباره است. رکوردهای موجود را مدیریت‌شده نگه می‌دارد، یکتایی شماره‌های جاری را حفظ می‌کند و شماره‌های خالی یا تکراری را فقط برای سوابق قبلی مجاز می‌سازد.

ساختار جدید را کنترل کنید:

~~~bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 "%PM_DATABASE%" -e "SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='emcore_trade_cases' AND COLUMN_NAME IN ('record_origin','numbering_issue','numbering_note','managed_pi_number')) OR (TABLE_NAME='emcore_trade_documents' AND COLUMN_NAME IN ('record_origin','managed_document_number')) OR (TABLE_NAME='emcore_trade_document_versions' AND COLUMN_NAME='file_role')) ORDER BY TABLE_NAME,ORDINAL_POSITION; SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN ('uq_emcore_trade_cases_managed_pi','uq_emcore_trade_documents_managed_number') ORDER BY TABLE_NAME;"
~~~

باید هفت ستون و دو شاخص یکتای مدیریت‌شده دیده شوند. اجرای migration نباید مقدار next_sequence هیچ شرکتی را تغییر دهد.

## استقرار API

هر دو فایل زیر برای اجرای ماژول لازم‌اند:

```bat
copy /Y "%EMCORE_RELEASE%\emcore_api\_trade_storage.php" "%PM_EMCORE_API%\_trade_storage.php"
copy /Y "%EMCORE_RELEASE%\emcore_api\emcore_trade_documents.php" "%PM_EMCORE_API%\emcore_trade_documents.php"

fc /B "%EMCORE_RELEASE%\emcore_api\_trade_storage.php" "%PM_EMCORE_API%\_trade_storage.php"
fc /B "%EMCORE_RELEASE%\emcore_api\emcore_trade_documents.php" "%PM_EMCORE_API%\emcore_trade_documents.php"

php -n -l "%PM_EMCORE_API%\_trade_storage.php"
php -n -l "%PM_EMCORE_API%\emcore_trade_documents.php"
```

هر دو دستور `fc` باید بدون تفاوت تمام شوند و هر دو فایل PHP زنده باید syntax validation را پاس کنند.

برای کنترل اولیهٔ session و endpoint، درخواست بدون نشست باید `401` برگرداند. به‌جای `PROCESSMAKER_HOST` نام واقعی میزبان را قرار دهید:

```bat
curl.exe -i -X POST -d "action=list" "http://PROCESSMAKER_HOST/emcore_api/emcore_trade_documents.php"
```

## نصب پنل

در ProcessMaker Designer:

- Dynaform مربوط به پرونده‌های بازرگانی را بسازید یا باز کنید.
- یک Panel WebControl اضافه کنید.
- محتوای کامل `panels\emcore_trade_documents_panel.html` را در Panel قرار دهید.
- فرم را ذخیره کنید و مرورگر را force-refresh کنید.
- پنل و `/emcore_api/emcore_trade_documents.php` را روی یک origin نگه دارید تا نشست ProcessMaker ارسال شود.
- در نخستین بازدید مطمئن شوید هشدار «فضای خصوصی اسناد پیکربندی نشده» نمایش داده نمی‌شود.

## تخصیص مجوزها

در ماتریس مجوز EMCORE، دسترسی ماژول `پرونده‌های بازرگانی` را به‌صورت هدفمند بدهید:

- کاربران گزارش‌گیر فقط به read نیاز دارند.
- شما و مسئول دفتر معمولاً به create، read و update نیاز دارید.
- delete را فقط به مدیران محدود بدهید؛ این قابلیت حذف نرم پرونده، نسخه یا پیوست را ممکن می‌کند.

مخفی‌بودن دکمه در پنل مجوز امنیتی نیست. پاسخ `403` API برای کاربر فاقد دسترسی باید جداگانه آزمایش شود.

## بارگذاری شش قالب

با کاربر دارای update، این شش فایل Word را از بخش «کتابخانهٔ شش قالب رسمی» بارگذاری کنید:

| شرکت | قالب‌ها |
|---|---|
| امیدکو | PI، CI و PL |
| امیدکو متال | PI، CI و PL |

پس از هر بارگذاری، قالب فعال را دوباره دانلود و با فایل مرجع مقایسه کنید. اگر قالب شامل مهر یا امضاست، read را فقط به افرادی بدهید که اجازهٔ دریافت آن فایل را دارند.

## آزمون پذیرش

آزمون ساخت پرونده را در محیط آزمایشی یا با نخستین پروندهٔ واقعی انجام دهید. ساخت پروندهٔ آزمایشی در محیط اصلی یک شماره را برای همیشه مصرف می‌کند؛ حذف نرم آن شماره را آزاد نمی‌کند.

- دسترسی ناشناس باید `401` برگرداند.
- کاربر بدون read باید `403` بگیرد.
- کاربر read-only باید فهرست، جزئیات و دانلود را ببیند، اما کنترل نوشتن نداشته باشد.
- ساخت پروندهٔ امیدکو باید شمارهٔ بعدی `EMDEX` را مصرف کند.
- ساخت پروندهٔ امیدکو متال باید شمارهٔ بعدی `EMDMET` را مصرف کند.
- شمارهٔ CI و PL باید به‌ترتیب `CI` و `PL` را به انتهای همان شمارهٔ PI اضافه کنند.
- دو ایجاد نزدیک به هم باید دو شمارهٔ یکتا و متوالی بسازند.
- بارگذاری CI پیش از تأیید PI باید با `409` رد شود.
- بارگذاری PL پیش از تأیید CI باید با `409` رد شود.
- تأیید یا صدور سند بدون حداقل یک نسخهٔ فایل باید رد شود.
- بارگذاری نسخهٔ دوم باید revision جدید بسازد و نسخهٔ اول قابل دانلود بماند.
- بارگذاری پیش‌نویس جدید پس از تأیید باید وضعیت را به draft برگرداند و تأیید قبلی را پاک کند.
- پسوند غیرمجاز، MIME ناسازگار و فایل بزرگ‌تر از سقف باید رد شوند.
- بارنامه، گواهی مبدأ، MTC یا قبض باسکول باید زیر همان پرونده دیده و دانلود شود.
- ثبت سابقهٔ قبلی باید شماره‌های PI، CI و PL را عیناً بپذیرد و next_sequence را تغییر ندهد.
- شماره‌های خالی یا تکراری باید برای سابقهٔ قبلی مجاز و در رابط علامت‌گذاری شوند.
- Word و PDF یک سند تاریخی باید با نقش‌های جداگانه و بدون تغییر وضعیت به پیش‌نویس یا صادرشده ثبت شوند.
- جست‌وجو باید شمارهٔ CI یا PL سابقه‌ای را که PI ندارد نیز پیدا کند.
- ایجاد پروندهٔ جدید باید از شماره‌ای که قبلاً در سوابق تاریخی ثبت شده عبور کند.
- تکمیل پرونده پیش از تأیید یا صدور هر سه سند باید با `409` رد شود.
- درخواست نوشتن بدون CSRF معتبر باید `403` برگرداند.
- متن فارسی و payload شامل HTML یا script باید به‌صورت متن نمایش داده شود و اجرا نشود.
- ایجاد، ویرایش، تغییر وضعیت، upload و حذف نرم باید در `emcore_audit_log` ثبت شوند.
- هر دانلود باید در `emcore_trade_download_log` ثبت شود.
- حذف نرم باید رکورد را از پنل خارج کند، اما شماره، فایل فیزیکی و سابقهٔ ممیزی را نگه دارد.

## بررسی پس از استقرار

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 "%PM_DATABASE%" -e "SELECT issuer_key,code_prefix,next_sequence FROM emcore_trade_issuers ORDER BY id; SELECT record_origin,COUNT(*) AS active_cases FROM emcore_trade_cases WHERE deleted_at IS NULL GROUP BY record_origin; SELECT document_type,document_status,COUNT(*) AS document_count FROM emcore_trade_documents GROUP BY document_type,document_status ORDER BY document_type,document_status; SELECT action,COUNT(*) AS audit_rows FROM emcore_audit_log WHERE module_key='trade_documents' GROUP BY action; SELECT actor_usr_uid,file_kind,original_filename,created_at FROM emcore_trade_download_log ORDER BY id DESC LIMIT 20;"
```

وجود فایل‌های ذخیره‌شده و رشد مصرف دیسک را نیز کنترل کنید:

```bat
dir /S "%EMCORE_TRADE_STORAGE%"
```

پایگاه داده و پوشهٔ فایل‌ها یک مجموعهٔ واحد هستند. برنامهٔ backup باید هر دو را با فاصلهٔ زمانی نزدیک نگه دارد و بازیابی دوره‌ای یک پرونده را آزمایش کند.

## توقف یا rollback امن

بعد از ورود دادهٔ واقعی، جدول‌ها یا پوشهٔ فایل را حذف نکنید. برای توقف فوری:

- Panel WebControl را از دسترس کاربران خارج کنید.
- دسترسی‌های ماژول را در ماتریس authorization بردارید.
- `trade_documents` را در `emcore_modules` غیرفعال کنید.
- در صورت نیاز، نسخهٔ قبلی API را از backup تأییدشده برگردانید.
- جدول‌ها، کانترها، audit و `%EMCORE_TRADE_STORAGE%` را برای اصلاح و بازیابی نگه دارید.

غیرفعال‌کردن `emcore_modules` به‌تنهایی جای لغو مجوزهای API را نمی‌گیرد. برای release اصلاحی، مجوزها را پس از استقرار و آزمون دوباره فعال کنید.
