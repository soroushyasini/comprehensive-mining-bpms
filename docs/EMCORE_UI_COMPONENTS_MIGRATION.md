# کامپوننت‌های مشترک رابط EMCORE و راهنمای مهاجرت

نسخه اول کامپوننت‌ها در `/emcore_assets/emcore-ui.js` و `emcore-ui.css` است و فقط پنل صورت جلسات از آن‌ها استفاده می‌کند. هیچ پنل موجود در این تغییر مهاجرت نکرده است. تقویم از هسته معتبر پنل مناقصات استخراج شده؛ تغییرات تقویم جدید به فایل قبلی اعمال نشده‌اند.

## قرارداد مصرف

پس از jQuery فعلی ProcessMaker، CSS و JavaScript مشترک را از همان origin بارگذاری و محتوای پنل را در یک عنصر با کلاس `emcore-ui` قرار دهید. CSS به همین ریشه محدود است؛ به `body` یا عناصر میزبان قواعد عمومی اضافه نکنید. API پنل، مجوزها و CSRF متعلق به هر پنل هستند؛ کامپوننت‌ها global AJAX hook نصب نمی‌کنند.

```html
<link rel="stylesheet" href="/emcore_assets/emcore-ui.css">
<section class="emcore-ui" dir="rtl">
  <label for="sample-date">تاریخ</label>
  <div><input id="sample-date" type="text" inputmode="numeric"></div>
</section>
<script src="/emcore_assets/emcore-ui.js"></script>
```

```javascript
var calendar = new EmcoreUI.DatePicker(document.getElementById('sample-date'), {
  today: function () { return EmcoreUI.todayFromGregorian(serverToday); }
});
// مقدار serverToday از lookup همان پنل می‌آید، مانند 2026-10-04.
```

| کامپوننت/تابع | قرارداد |
|---|---|
| `DatePicker(input,{today})` | تقویم شنبه‌محور؛ انتخاب، change ورودی را صادر می‌کند؛ `close(), destroy()` |
| `ParticipantPicker(root,options)` | `search(text)` یک jqXHR با `data` array برمی‌گرداند؛ `multiple, allowExternal, label, onChange` |
| picker methods | `get(), set(items), setDisabled(boolean), destroy()`؛ name_snapshot و usr_uid یا person_key |
| `Modal(overlay,{beforeClose})` | داخل overlay یک `role=dialog` لازم است؛ `open(trigger), close(), isOpen()`؛ trap و بازگردانی focus |
| `UploadQueue(options)` | `prepare(files), start(), remaining(), isRunning()`؛ صف با شناسه مستقل برای هر فایل |
| queue callbacks | `send(file,requestId,onProgress)` یک jqXHR؛ `onProgress,onSuccess,onError,onFinish` |
| helpers | `latinDigits,persianDigits,requestId,errorText,fileSize,parseDate,formatDate,todayFromGregorian,personKey` |

نام، سازمان، filename و متن‌ها با DOM و `.text()` ساخته شوند. در picker، کاربر می‌تواند نام بیرونی را در کادر جست‌وجو بنویسد و با «افزودن فرد بیرونی» ثبت کند؛ جست‌وجو دارای تأخیر کوتاه، لغو درخواست قبلی و کنترل پاسخ کهنه است. برای فیلتر افراد ثبت‌شده، `allowExternal:false` و endpoint مناسب آرشیو را استفاده کنید.

Queue به مقصد HTTP، file_role یا lock_version وابسته نیست. پنل این داده‌ها را در `send` می‌سازد و در `onSuccess` نسخه جدید را نگه می‌دارد. retry با `start()` فقط از فایل ناموفق به بعد و همان requestId انجام می‌شود. `prepare()` انتخاب تازه را جایگزین می‌کند و هنگام اجرا نباید انتخاب، نقش فایل، رکورد فعال یا پنجره عوض شوند. خطای بخشی rollback گروهی نیست؛ فایل‌های قبلی موفق می‌مانند.

Modal از تغییر focus به خارج جلوگیری می‌کند و siblingهای overlay را هنگام نمایش inert می‌کند. `beforeClose` برای جلوگیری از بستن در زمان ذخیره/بارگذاری و هشدار تغییر ذخیره‌نشده است. calendar/picker، Escape خود را مصرف می‌کنند تا پنجره اصلی هم‌زمان بسته نشود. فایل CSS وضعیت را با متن و رنگ نمایش می‌دهد؛ رنگ به‌تنهایی کافی نیست.

## انتقال پنل‌های فعلی در آینده

۱. ابتدا از پنل و API هدف snapshot بگیرید و آزمون رفتار فعلی، endpoint، مجوز و CSRF را ثبت کنید. انتقال یک پنل در هر تغییر انجام شود.

۲. فایل‌های مشترک را نصب، ریشه UI و شناسه‌های یکتای پنل را تعیین کنید. stylesheet محلی نباید توکن‌های corporate را با قواعد عمومی میزبان بازنویسی کند. وابستگی فعلی jQuery حفظ شود.

۳. تقویم محلی را با `DatePicker` جایگزین و هسته تکراری تقویم را فقط پس از پاس‌شدن آزمون‌های پنل هدف حذف کنید. timezone تاریخ امروز و قرارداد API تاریخ شمسی تغییر نکند.

۴. markup انتخاب/نوار پیشرفت فایل حفظ یا بازسازی و منطق صف با `UploadQueue` جایگزین شود. endpoint تک‌فایلی، نقش‌ها، CSRF و محدودیت فایل ماژول هدف ثابت بمانند. فقط ماژولی که retry را server-side تضمین می‌کند می‌تواند از ارسال دوباره خودکار درخواست مبهم استفاده کند.

۵. modal و انتخاب افراد فقط برای نیاز واقعی پنل منتقل شوند. هنگام جایگزینی یک پنل، listenerهای قدیمی و کامپوننت‌ها با `destroy()` آزاد شوند؛ فایل مشترک یک بار در هر document بارگذاری شود.

۶. آزمون regression هدف، مرز سال و کبیسه، خطای میانی upload، نشست منقضی، read-only، نام و filename مخرب، صفحه‌کلید و اندازه موبایل اجرا شوند. در صورت شکست، نسخه قبلی همان پنل بازگردد. انتقال پنل دیگر تا پذیرش کامل پنل نخست انجام نشود.

تغییر API عمومی کامپوننت‌ها باید نسخه داشته باشد و پنل‌های مصرف‌کننده آزموده شوند؛ ارتقای `emcore-ui.js` به‌تنهایی پذیرش رفتار ماژول‌ها محسوب نمی‌شود.
