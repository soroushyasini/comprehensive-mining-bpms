# ماژول مناقصات و مزایدات EMCORE

## هدف

این ماژول جایگزین فرم DynaForm و جدول XCRUD قدیمی `prc_db_mozayedat_monaghesat_copy1` است. همهٔ عملیات روزمره از Panel WebControl جدید و API امن EMCORE انجام می‌شود؛ هویت کاربر، مجوزها، CSRF، ممیزی و حذف نرم همان قرارداد مشترک سایر ماژول‌ها را دنبال می‌کنند.

فایل DynaForm فقط برای استخراج تعریف فیلدها، گزینه‌ها و رفتارهای رابط بررسی شده است. جدول موجود `prc_db_mozayedat_monaghesat_copy1` منبع authoritative مهاجرت داده است. کد JavaScript و triggerهای داخل خروجی فرم، پیکربندی اجرایی ماژول جدید نیستند.

## قواعد دامنه

1. هر فراخوان از نوع `tender` (مناقصه) یا `auction` (مزایده) است.
2. عنوان و تاریخ ثبت برای رکوردهای جدید الزامی‌اند.
3. تاریخ‌های شمسی با قالب `YYYY/MM/DD` وارد می‌شوند و API مقدار میلادی متناظر را در همان عملیات می‌سازد.
4. وضعیت فرصت از `response_deadline_en` محاسبه می‌شود و هیچ ستون `LEFT_DAYS` ذخیره‌شده‌ای وجود ندارد:
   - `expired`: مهلت گذشته؛
   - `urgent`: از امروز تا بازهٔ هشدار رکورد؛
   - `open`: بیش از بازهٔ هشدار فرصت دارد؛
   - `no_deadline`: مهلت پاسخ ثبت نشده است.
5. مبلغ و تناژ عمداً متن تجاری معتبرند، نه عدد اجباری. دادهٔ قدیمی شامل بازه‌هایی مانند `20000-30000`، عبارت «نامشخص» و تضمین‌هایی مانند «۷٪ مبلغ قرارداد» است. تبدیل اجباری این مقادیر به `DECIMAL` باعث از دست رفتن معنا می‌شود.
6. دسته‌بندی/زیرگروه/کالا در رابط برای واحد بازرگانی نمایش داده می‌شوند. محدوده حفاری برای واحد حفاری یا محدوده معدنی نمایش داده می‌شود.
7. دلیل علاقه فقط هنگام وضعیت `interested` از رابط/API جاری نگه‌داری می‌شود.
8. ویرایش با `lock_version` کنترل می‌شود؛ ذخیره روی نسخهٔ قدیمی با HTTP 409 رد می‌شود.
9. حذف رکورد و فایل نرم است. فایل فیزیکی برای ممیزی پاک نمی‌شود.
10. شناسه‌های فایل خوانده‌شده از جدول قدیمی فقط «مرجع قدیمی» هستند. تا زمانی که محتوای واقعی به مخزن خصوصی منتقل نشده باشد دانلودشدنی نشان داده نمی‌شوند.

## مدل داده

| جدول | مسئولیت |
|---|---|
| `emcore_procurement_notices` | مشخصات، مهلت‌ها، دسته‌بندی و نتیجه فراخوان |
| `emcore_procurement_files` | مدارک فراخوان و فایل ارسال نهایی، یا مرجع فایل قدیمی |
| `emcore_procurement_download_log` | سابقهٔ دانلود فایل‌های مخزن خصوصی |
| `emcore_procurement_import_batches` | مشخصات و آمار هر اجرای backfill از جدول قدیمی |

رکوردهای مهاجرتی `record_origin = legacy`، شناسهٔ `legacy_source_id` و تصویر کامل ردیف اولیه در `legacy_source_data` دارند. `legacy_source_id` یکتا است و اجرای دوبارهٔ importer رکورد تکراری نمی‌سازد.

## قرارداد API

Endpoint: `/emcore_api/emcore_procurement_notices.php`

همهٔ درخواست‌ها `POST` هستند. هویت فقط از نشست فعال ProcessMaker خوانده می‌شود.

| Action | قابلیت | CSRF | شرح |
|---|---|---|---|
| `lookups` | read | خیر | گزینه‌های متمایز، وضعیت مخزن، مجوزها و توکن |
| `list` | read | خیر | فهرست صفحه‌بندی‌شده، فیلترها و خلاصه آماری |
| `get` | read | خیر | رکورد کامل و فهرست فایل‌ها |
| `download_file` | read | خیر | دانلود کنترل‌شده و ثبت لاگ |
| `create` | create | بله | ایجاد فراخوان جدید |
| `update` | update | بله | ویرایش با کنترل نسخه |
| `upload_file` | update | بله | افزودن فایل به یکی از دو نقش |
| `delete_file` | delete | بله | حذف نرم فایل |
| `delete` | delete | بله | حذف نرم فراخوان |

فیلترهای `list` شامل `search`، `notice_type`، `deadline_state`، `participation_status`، `responsible_unit` و `source_name` هستند. پاسخ دارای `pagination.total_pages` و `summary` است.

## فایل‌ها

نقش‌های فایل:

- `notice_document`: مدارک و پیوست‌های فراخوان؛
- `final_submission`: بسته یا فایل ارسال نهایی.

پسوندهای مجاز: `doc`، `docx`، `pdf`، `xls`، `xlsx`، `jpg`، `jpeg`، `png` و `zip`.

مخزن باید خارج از web root باشد. نام ذخیره‌شده تصادفی است و API پسوند، MIME، اندازه و SHA-256 را کنترل می‌کند. مسیر واقعی فایل هیچ‌گاه در JSON نمایش داده نمی‌شود.

## نگاشت جدول قدیمی

| ستون قدیمی | فیلد جدید |
|---|---|
| `type` | `notice_type` |
| `akhz` | `source_name` |
| `name` | `title` |
| `tonage` | `quantity_text` |
| `mozayede_shomare` | `reference_number` |
| `mablagh`, `vahed` | `amount_text`, `currency` |
| `dastgahejrai` | `contracting_authority` |
| `nahve_sherkat` | `submission_method` |
| `deadline_asnad` | `documents_deadline_fa/en` |
| `deadline_pasokh` | `response_deadline_fa/en` |
| `insert_date` | `registered_on_fa/en` |
| `alarm` | `alert_lead_days` |
| `vahed_marbute` | `responsible_unit` |
| `category`, `subcategory`, `products` | دسته‌بندی متنی جدید |
| `vaze_sherkat` | `participation_status` |
| `vaze_tamdid` | `is_extended` سه‌حالته |
| `file`, `file_nahaee`, `file_field` | مرجع‌های `emcore_procurement_files` |
| `LEFT_DAYS` | منتقل نمی‌شود؛ هر درخواست دوباره محاسبه می‌کند |

ابزار `tools/import_legacy_procurement_notices.php` مستقیماً و فقط از جدول allowlisted `prc_db_mozayedat_monaghesat_copy1` می‌خواند. هیچ مسیر CSV در backfill وجود ندارد و جدول قدیمی نیز تغییر نمی‌کند.

مقادیر خالی نوع یا وضعیت در سابقهٔ قدیمی با `unknown` وارد می‌شوند تا دروغ داده‌ای ساخته نشود. رابط هنگام ویرایش، کاربر را به انتخاب مقدار معتبر وادار می‌کند. تاریخ یا عنوان ناقص نیز در گزارش dry-run علامت‌گذاری می‌شود.

## داشبورد تحلیلی

گزارش تحلیلی جدید از endpoint مستقل `/emcore_api/emcore_procurement_analytics.php` و همان مجوز `procurement_notices:read` استفاده می‌کند. این endpoint فقط از جدول‌های normalized جدید می‌خواند و هیچ وابستگی runtime به جدول legacy یا SQL داخل DynaForm ندارد.

پنج معنای گزارش قدیمی—دستگاه اجرایی/نوع، واحد مربوطه، دسته‌بندی، وضعیت شرکت و درخت دسته/زیردسته/محصول—حفظ شده‌اند. برخلاف فرم قبلی، همهٔ نمودارها دقیقاً یک دامنهٔ فیلتر مشترک دارند، خطا با دادهٔ ساختگی پوشانده نمی‌شود و نوع/وضعیت نامشخص و تاریخ‌های ناقص در بخش کیفیت داده دیده می‌شوند. تعریف کامل شاخص‌ها، سقف پاسخ‌ها و قرارداد دو action در [PROCUREMENT_ANALYTICS.md](PROCUREMENT_ANALYTICS.md) آمده است.
