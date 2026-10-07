# استقرار کارت‌های ویزیت

برای ورود مستقیم SQL و انتقال یک پوشهٔ آمادهٔ تصاویر، از
[راهنمای بستهٔ آماده](BUSINESS_CARDS_PREPARED_IMPORT.md) استفاده کنید. در این روش
parser و importer روی سرور لازم نیستند. فقط اصل تصاویر ذخیره می‌شود. روش CLI زیر، مسیر جایگزین
برای ورود از منابع خام است؛ هر دو از source_key یکسان استفاده می‌کنند.

## پیش‌نیاز و ترتیب

نسخهٔ اول روی قرارداد جاری PHP/PDO EMCORE اجرا می‌شود. GD با JPEG/PNG، Fileinfo،
mbstring و PDO MySQL لازم‌اند. آزمون مستقل با PHP 8.2 و MySQL 8.4 انجام شده است؛
PHP نصب ProcessMaker باید جداگانه lint و پذیرفته شود. Migration به 001 و 002 وابسته
است و به شرکت‌ها، اشخاص یا تابع تبدیل تاریخ نیاز ندارد.

۱. workspace واقعی و نصب 001/002 را کنترل و از دیتابیس و تنظیمات پشتیبان بگیرید.
Migration `014_emcore_business_cards.sql` را اجرا کنید. اجرای دوباره جدول‌ها را پاک
نمی‌کند و مجوز عمومی اعطا نمی‌کند. کشورها INSERT IGNORE دارند.
Migration `015_emcore_business_cards_original_only.sql` را نیز اجرا کنید؛ برای
نصب‌های قبلی، ستون‌های قدیمی thumbnail را nullable می‌کند. داده‌ها و اصل تصاویر
محفوظ‌اند. بستهٔ SQL آماده هر دو migration را شامل می‌شود.

۲. مخزن قابل نوشتن خارج از تمام مسیرهای web-served ایجاد کنید. تنظیمات موجود را
با نمونه جایگزین نکنید؛ فقط کلیدهای زیر را به پیکربندی خصوصی فعلی اضافه کنید:

```php
'business_cards_storage_root' => 'C:\\pmlearning\\emcore-private\\business-cards',
'business_cards_max_upload_bytes' => 10485760,
```

معادل محیطی: `EMCORE_BUSINESS_CARDS_STORAGE_ROOT` و
`EMCORE_BUSINESS_CARDS_MAX_UPLOAD_BYTES`؛ محیط بر فایل اولویت دارد. مسیر نمونه را
با مسیر واقعی نصب تطبیق دهید. سقف PHP برای upload حداقل 10M و post حداقل 12M باشد.
دو تصویر ترکیبی بزرگ در منابع فعلی، برای اعتبارسنجی محتوای تصویر حافظهٔ بیشتری می‌خواهند؛ برای
CLI ورود اولیه `memory_limit=512M` تعیین کنید. API پیش از decode، کمبود حافظه را
با خطای اعتبارسنجی رد می‌کند. مقدار memory_limit سرور باید با حجم تصاویر مجاز و
تعداد workerهای آن متناسب باشد.

۳. helperهای `_business_cards_domain.php` و `_business_cards_storage.php` را همراه
bootstrap/audit/module_permissions موجود در پوشهٔ API نصب کنید. ابزار CLI و منابع
را بیرون از DocumentRoot نگه دارید. با پنل authorization به کاربر واقعی ورود اولیه
مجوزهای business_cards:read/create/update بدهید و UID او را از USERS تأیید کنید.

۴. در checkout خصوصی دارای cart، manifest بسازید. Python استاندارد 3.9+ کافی است:

```powershell
python tools/build_business_cards_manifest.py --output dataset/business_cards_manifest.json
```

خروجی باید records=443، images=304، without_image=139، cards_with_coordinates=295 و
coordinate_points=297 باشد. دو ورودی unstructured_identity واقعی حفظ می‌شوند.
JSON و تصاویر منبع دادهٔ عملیاتی خصوصی هستند؛ آن‌ها را در مسیر عمومی نصب نکنید.

۵. importer را ابتدا بدون commit اجرا کنید. تنظیمات CLI باید به همان workspace و
مخزن نصب API اشاره کنند. مقدار `<ACTIVE_UID>` را با UID واقعی جایگزین کنید:

```powershell
php -d memory_limit=512M tools/import_business_cards.php --manifest=dataset/business_cards_manifest.json --source-root=cart --actor=<ACTIVE_UID>
php -d memory_limit=512M tools/import_business_cards.php --manifest=dataset/business_cards_manifest.json --source-root=cart --actor=<ACTIVE_UID> --commit
php -d memory_limit=512M tools/import_business_cards.php --manifest=dataset/business_cards_manifest.json --source-root=cart --actor=<ACTIVE_UID> --commit
```

در PowerShell مقدار actor را به‌صورت رشتهٔ UID وارد کنید؛ نشانه‌های `< >` فقط
placeholder مستندات‌اند. اجرای دوم commit باید new=0 و skipped=443 بدهد. failed
یا changed، کد خروج یک دارد؛ تا رفع آن‌ها پنل را منتشر نکنید. import هر کارت مستقل
است؛ اجرای مجدد فقط موارد تکمیل‌نشده را اضافه می‌کند و دادهٔ کاربر را تغییر نمی‌دهد.

۶. Endpoint `emcore_business_cards.php` و فایل‌های زیر را از همان origin نصب کنید:

```text
emcore_assets/emcore-ui.css
emcore_assets/emcore-ui.js
emcore_assets/fonts/Vazirmatn-{Regular,Medium,SemiBold,Bold}.ttf
emcore_assets/business-cards.css
emcore_assets/business-cards.js
panels/emcore_business_cards_panel.html
```

پنل را در WebControl نصب و Dynaform را force-refresh کنید. jQuery موجود ProcessMaker
مصرف می‌شود؛ نسخهٔ fixture-jquery و فایل‌های tools/fixtures را نصب نکنید. نشست API
باید با ProcessMaker سازگار باشد. مجوز کاربران نهایی را پس از پذیرش تنظیم کنید.

## کنترل پذیرش

پیش از ورود دادهٔ دستی، شمارش‌ها را بررسی کنید:

```sql
SELECT COUNT(*) FROM emcore_business_cards WHERE deleted_at IS NULL;
SELECT COUNT(*) FROM emcore_business_card_files WHERE current_card_id IS NOT NULL;
SELECT COUNT(*) FROM emcore_business_card_source_records;
SELECT COUNT(DISTINCT card_id) FROM emcore_business_card_locations WHERE latitude IS NOT NULL;
SELECT COUNT(*) FROM emcore_business_card_locations WHERE latitude IS NOT NULL;
```

مقادیر باید به‌ترتیب 443،304،443،295،297 باشند. integrity فایل‌ها، منابع مبهم،
نمونهٔ کشور تجاری متفاوت از کشور نشانی و دو نقطهٔ تکمیلی را کنترل کنید.

با کاربران read-only و editor، ثبت بدون تصویر، یک اسکن ترکیبی، دکمهٔ popup در
جدول، Escape/بازگشت focus، دانلود، جایگزینی، خطای فایل، تعارض ویرایش و حذف تصویر
را روی Dynaform واقعی بپذیرید. API مستقیم باید درخواست بی‌مجوز یا بی‌CSRF را رد کند.

آزمون خودکار فقط در fixture جدا:

```powershell
node tools/check_business_cards_release.js
./tools/run_business_cards_fixture.ps1
```

Runner فقط containerهای نام‌گذاری‌شدهٔ خودش و schema
`emcore_business_cards_fixture` را استفاده می‌کند و در پایان پاک می‌کند.
`-KeepRunning` برای بررسی نتیجهٔ محلی است؛ اگر fixture قبلی موجود باشد، runner
برای جلوگیری از برخورد متوقف می‌شود. fixture هیچ credential واقعی نمی‌خواند.

## اصلاح پنل داخل Dynaform — ۱۴۰۵/۰۷/۱۵

دکمه‌های پنل باید `type="button"` داشته باشند؛ نوع پیش‌فرض button در فرم میزبان،
submit است و می‌تواند case را به صفحهٔ پایان فرایند بفرستد. پنل از form داخلی
استفاده نمی‌کند؛ ذخیره، فیلتر و اعتبارسنجی پیشنهاد کشور در خود پنل انجام می‌شوند.
Enter در فیلدهای پنل نیز فرم فرایند را ارسال نمی‌کند. آزمون مرورگر هر دو حالت
درج پنل در فرم میزبان و تجزیهٔ HTML داخل همان فرم را بررسی می‌کند.

برای نصب این اصلاح، `emcore_assets/business-cards.js` و HTML پنل
`panels/emcore_business_cards_panel.html` را با هم به‌روز کنید. script پنل دارای
شمارهٔ نسخه برای تازه‌شدن cache مرورگر است. API، migration، تصاویر و seed تغییری
نیاز ندارند. ستون اول، شناسهٔ کارت است؛ برای بررسی تصاویر، فیلتر «دارای تصویر»
را اعمال کنید. مرتب‌سازی پیش‌فرض `id desc` در import اولیه، ردیف‌های بدون عکس
را ابتدا نشان می‌دهد؛ غیرفعال‌بودن دکمهٔ آن‌ها به‌معنی خرابی مخزن نیست.

## بازگشت انتشار

پنل را از دسترس خارج و API سازگار قبلی را برگردانید. جدول‌ها، تصاویر و audit را
حذف نکنید. فایل‌های موفقِ جایگزین‌شده برای ممیزی محفوظ‌اند؛ برنامهٔ پاک‌سازی
فیزیکی بخشی از v1 نیست. نسخهٔ قبلی تصویر خودکار فعال نمی‌شود. تغییر دادهٔ واردشده
از طریق فرم و ممیزی انجام می‌شود؛ source_records تاریخچهٔ اولیه را حفظ می‌کند.
