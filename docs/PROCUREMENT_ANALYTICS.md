# تحلیل مناقصات و مزایدات EMCORE

## هدف و معیار پذیرش

این قابلیت، گزارش DynaForm قدیمی را با یک داشبورد مستقل، فارسی، راست‌به‌چپ و فقط‌خواندنی جایگزین می‌کند. مخاطب آن کاربران دارای دسترسی خواندن ماژول `procurement_notices` هستند. منبع حقیقت فقط ردیف‌های حذف‌نشدهٔ `emcore_procurement_notices` و فایل‌های فعال مرتبط است؛ جدول `prc_db_mozayedat_monaghesat_copy1` در زمان اجرای داشبورد خوانده نمی‌شود.

معیارهای پذیرش:

1. پنج معنای اصلی گزارش قدیمی حفظ شوند: تفکیک نوع فراخوان بر اساس دستگاه اجرایی، توزیع واحد مسئول، توزیع دسته‌بندی، توزیع وضعیت شرکت و مرور سلسله‌مراتبی دسته/زیردسته/محصول.
2. یک مجموعه فیلتر مشترک بر همهٔ شاخص‌ها و نمودارها اعمال شود؛ بازهٔ تاریخ بر `registered_on_en` است و تاریخ شمسی فقط ورودی/نمایش است.
3. وضعیت مهلت از تاریخ جاری و `response_deadline_en`/`alert_lead_days` محاسبه شود و از `LEFT_DAYS` قدیمی استفاده نشود.
4. دادهٔ خالی، دادهٔ نامعتبر و خطای شبکه هرگز با دادهٔ نمونه یا ساختگی جایگزین نشوند.
5. نوع یا وضعیت `unknown` و تاریخ‌های ناقص/وارونه در بخش کیفیت داده آشکار باشند و از مخرج شاخص‌ها حذف پنهانی نشوند.
6. API فقط با نشست و مجوز خواندن ProcessMaker پاسخ دهد، تمام فیلترها را در مرز ورودی اعتبارسنجی کند و SQL پارامتری باشد.
7. پنل بدون کتابخانهٔ نمودار راه‌دور، با SVG داخلی، جدول دادهٔ قابل خواندن، ناوبری صفحه‌کلید و چیدمان پاسخ‌گو در عرض‌های ۳۲۰، ۷۶۸، ۱۰۲۴ و ۱۴۴۰ پیکسل کار کند.

## فناوری و قرارداد

- Backend: PHP 8.1، PDO/MySQL و bootstrap مشترک EMCORE.
- Frontend: HTML/CSS/JavaScript و jQuery موجود در ProcessMaker؛ نمودارهای SVG بدون dependency جدید.
- Endpoint: `POST /emcore_api/emcore_procurement_analytics.php`.
- مجوز: `procurement_notices:read` برای هر دو action.

### Action: `lookups`

خروجی شامل بازهٔ تاریخ داده‌ها و گزینه‌های متمایز `source_name`، `contracting_authority`، `responsible_unit` و `category_name` است. هر فهرست سقف ثابت دارد تا پاسخ نامحدود نشود.

### Action: `dashboard`

ورودی‌های اختیاری:

| فیلد | قرارداد |
|---|---|
| `date_from_fa`, `date_to_fa` | تاریخ شمسی `YYYY/MM/DD`؛ بازه بسته و بر اساس تاریخ ثبت |
| `notice_type` | `tender`, `auction`, `unknown` |
| `participation_status` | `registered`, `interested`, `documents_submitted`, `won`, `lost`, `unknown` |
| `source_name` | تطبیق دقیق، حداکثر ۲۵۵ نویسه |
| `contracting_authority` | تطبیق دقیق، حداکثر ۲۵۵ نویسه |
| `responsible_unit` | تطبیق دقیق، حداکثر ۶۴ نویسه |
| `category_name` | تطبیق دقیق، حداکثر ۲۵۵ نویسه |
| `minimum_authority_total` | حداقل تعداد برای نمودار دستگاه اجرایی، ۱ تا ۱۰۰۰۰۰ |
| `top_n` | تعداد دستگاه‌های اجرایی، ۵ تا ۳۰ |

پاسخ موفق شکل ثابتی دارد:

```json
{
  "success": true,
  "data": {
    "summary": {},
    "monthly": [],
    "authorities": [],
    "responsible_units": [],
    "participation_statuses": [],
    "deadline_states": [],
    "categories": [],
    "category_hierarchy": []
  },
  "meta": {
    "generated_at": "ISO-8601",
    "filters": {},
    "quality": {},
    "limits": {},
    "semantics": {}
  }
}
```

## معنای شاخص‌ها

- `total`: تعداد ردیف‌های حذف‌نشده در دامنهٔ فیلتر.
- `tender_count`، `auction_count` و `unknown_type_count`: تفکیک کامل نوع؛ مقدار نامشخص حذف نمی‌شود.
- `open_count`: مهلت پاسخ ثبت شده و فاصله تا امروز بیش از بازه هشدار است.
- `urgent_count`: مهلت پاسخ از امروز تا `alert_lead_days` روز آینده است.
- `expired_count`: مهلت پاسخ پیش از امروز است.
- `no_deadline_count`: مهلت پاسخ ثبت نشده است.
- `file_coverage_count`: تعداد فراخوان‌هایی که حداقل یک فایل فعال یا مرجع فایل قدیمی دارند؛ این شاخص به معنی دانلودپذیر بودن فایل قدیمی نیست.
- روند ماهانه بر ماه شمسی `registered_on_fa` گروه‌بندی می‌شود و حداکثر ۲۴ ماه دارای داده را نمایش می‌دهد.
- نمودار دستگاه اجرایی فقط گروه‌هایی را نشان می‌دهد که به حداقل انتخاب‌شده رسیده‌اند و سپس حداکثر `top_n` گروه نخست را برمی‌گرداند.
- درخت دسته‌بندی، مقدار خالی و نشانگر متنی قدیمی `NaN` را صریحاً `نامشخص` نمایش می‌دهد. API حداکثر ۱۰۰۰ ترکیب دسته/زیردسته/محصول را می‌پذیرد و بریده‌شدن را در metadata اعلام می‌کند.

## ساختار پروژه

```text
emcore_api/emcore_procurement_analytics.php    API فقط‌خواندنی و تجمیع‌ها
panels/emcore_procurement_analytics_panel.html پنل قابل استقرار ProcessMaker
tools/check_procurement_analytics_release.js   کنترل قرارداد و syntax ایستا
docs/PROCUREMENT_ANALYTICS.md                  مشخصات، معنا و راهنمای استقرار
tasks/plan.md                                  برنامهٔ پیاده‌سازی فعلی
tasks/todo.md                                  وضعیت برش‌های قابل راستی‌آزمایی
```

## سبک پیاده‌سازی

نام فیلدهای API انگلیسی و `snake_case` باقی می‌ماند و برچسب فارسی فقط در رابط نگاشت می‌شود. همهٔ داده‌های پایگاه داده هنگام ساخت DOM با `textContent`/متدهای متنی jQuery درج می‌شوند؛ HTML تولیدشده از دادهٔ خام ساخته نمی‌شود.

```php
$stmt = $db->prepare("SELECT COUNT(*) FROM emcore_procurement_notices p WHERE {$whereSql}");
$stmt->execute($params);
```

## راهبرد آزمون و فرمان‌ها

کنترل محلی مستقل از پایگاه داده:

```powershell
node tools\check_procurement_analytics_release.js
node tools\check_procurement_notices_release.js
```

روی میزبان ProcessMaker که PHP در دسترس است:

```cmd
php -n -l emcore_api\emcore_procurement_analytics.php
curl.exe -i -X POST -d "action=lookups" http://localhost/emcore_api/emcore_procurement_analytics.php
```

درخواست `curl` بدون نشست باید `401` برگرداند. پذیرش دستی شامل ورود با کاربر read-only، مقایسه شمارش بدون فیلتر با جدول اصلی، آزمایش فیلتر تاریخ/نوع/وضعیت، drill-down دسته‌ها، حالت بدون داده، ناوبری صفحه‌کلید و چهار عرض هدف است.

## مرزها

- همیشه: حذف نرم را رعایت کن، فیلترها را پارامتری کن، unknown و missing را آشکار نگه دار و معنای هر شاخص را همراه پاسخ ثبت کن.
- ابتدا هماهنگ کن: تغییر schema، افزودن dependency، تغییر مدل مجوز یا تعریف تجاری مبلغ/تناژ.
- هرگز: خواندن runtime از جدول legacy، اجرای SQL داخل DynaForm، ساخت دادهٔ fallback نمایشی، بارگذاری CDN یا افشای مسیر فایل خصوصی.
