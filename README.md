<p align="center">
  <img src="https://img.icons8.com/color/96/youtube-play.png" alt="YouTube Downloader" width="72">
</p>

<h1 align="center">🎬 Bale YouTube Downloader</h1>

<p align="center">
  <strong>ربات دانلودر یوتیوب برای پیام‌رسان بله</strong>
</p>

<p align="center">
با قابلیت جستجو در یوتوب و دانلود ویدئو از یوتوب همراه با آرشیو خودکار توی کانال شخصی خودت 
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Version-5.0-blue" alt="Version">
  <img src="https://img.shields.io/badge/PHP-8.1+-purple" alt="PHP">
  <img src="https://img.shields.io/badge/License-MIT-green" alt="License">
</p>

<br>

<!-- ────────────────────────────────────────────── -->

<h2>✨ چرا این ربات؟</h2>

<p>
یه ربات سبک برای پیام‌رسان <strong>بله</strong> که دانلود ویدیوهای یوتیوب رو با کلی امکانات برات انجام میده:
</p>

<table>
  <tr><td>🎥</td><td>دانلود با کیفیت‌های مختلف — از ۴۸۰p تا ۴K یا فقط صدا</td></tr>
  <tr><td>📝</td><td>زیرنویس فارسی و انگلیسی — خودکار دانلود و بسته‌بندی میشه</td></tr>
  <tr><td>📂</td><td>آرشیو فایل‌ها توی کانال شخصی خودت</td></tr>
  <tr><td>🔍</td><td>جستجوی ویدیو — اسم رو تایپ کن، ربات پیدا میکنه</td></tr>
  <tr><td>⏳</td><td>صف هوشمند — اگه کاربرا زیاد باشن، خودکار مدیریت میشه</td></tr>
  <tr><td>📦</td><td>فایل‌های بزرگ خودکار split و zip میشن (با رمز دلخواه)</td></tr>
  <tr><td>🔒</td><td>جوین اجباری — کاربرا قبل از استفاده عضو کانال اسپانسر میشن</td></tr>
</table>

<blockquote>
هاست تو فقط صف رو مدیریت میکنه — کارهای سنگین رو <strong>GitHub Actions</strong> رایگان انجام میده.
</blockquote>

<br>

<!-- ────────────────────────────────────────────── -->

<h2>🚀 نصب در ۳ قدم</h2>

<h3>📋 پیش‌نیازها</h3>

<ul>
  <li>هاست cPanel با PHP 8.1 یا بالاتر</li>
  <li>ربات بله (ساخته‌شده با <a href="https://ble.ir/botfather">BotFather</a>)</li>
  <li>توکن گیت‌هاب با دسترسی <code>repo</code> و <code>workflow</code> — <a href="https://github.com/settings/tokens">از اینجا بساز</a></li>
</ul>

<br>

<h3>۱. آپلود فایل‌ها</h3>

<p>کل پروژه رو توی مسیر <code>public_html/youtube-downloader/</code> هاست آپلود کن.</p>

<br>

<h3>۲. تنظیم اطلاعات توی gateway.php</h3>

<p>فایل <code>gateway.php</code> رو باز کن و این ۵ خط رو با اطلاعات خودت جایگزین کن:</p>

<pre><code>putenv('BALE_BOT_TOKEN=توکن-ربات-بله');
putenv('GH_PAT=توکن-گیت-هاب');
putenv('GITHUB_OWNER=نام-کاربری-گیت-هاب');
putenv('GITHUB_REPO=نام-ریپازیتوری');
putenv('CHANNEL_ID=شناسه-کانال-آرشیو');</code></pre>

<br>

<h3>۳. تنظیم Webhook و Secrets گیت‌هاب</h3>

<p><strong>الف)</strong> آدرس زیر رو توی مرورگر باز کن:</p>

<pre><code>https://yourdomain.com/youtube-downloader/gateway.php</code></pre>

<p><strong>ب)</strong> آپدیت توکن بازو بله:</p>

<pre><code>https://tapi.bale.ai/bot%3CYOUR_TOKEN%3E/setWebhook?url=https://yourdomain.com/youtube-downloader/gateway.php</code></pre>

<p><strong>پ)</strong> توی ریپازیتوری گیت‌هاب برو به <strong>Settings → Secrets → Actions</strong> و این دو تا رو اضافه کن:</p>

<table>
  <tr><th>Secret</th><th>مقدار</th></tr>
  <tr><td><code>BALE_BOT_TOKEN</code></td><td>توکن ربات بله</td></tr>
  <tr><td><code>GH_PAT</code></td><td>توکن گیت‌هاب</td></tr>
</table>

<br>

<!-- ────────────────────────────────────────────── -->

<h2>⚙️ شخصی‌سازی</h2>

<p>همه محدودیت‌ها توی فایل <code>Config.php</code> قابل تغییره:</p>

<ul>
  <li><strong>فاصله بین دانلودها:</strong> ۵ دقیقه</li>
  <li><strong>سقف روزانه هر کاربر:</strong> ۵۰ تا</li>
  <li><strong>حجم هر پارت فایل:</strong> ۲۰ مگابایت</li>
  <li><strong>حداکثر دانلود همزمان هر کاربر:</strong> ۳ تا</li>
</ul>

<br>

<!-- ────────────────────────────────────────────── -->

<h2>❤️ حمایت و کپی‌رایت</h2>

<p>این پروژه با عشق و متن‌باز توسعه داده شده. استفاده و تغییرش برای همه آزاده — فقط چندتا اصل ساده:</p>

<ul>
  <li>⭐ <strong>ستاره دادن</strong> بهترین راه تشویقه</li>
  <li>🍴 <strong>Fork کردن</strong> و شخصی‌سازی کاملاً آزاده</li>
  <li>📝 ذکر <strong>نام توسعه‌دهنده (<a href="https://github.com/khashayardev">@khashayardev</a>)</strong> و لینک به ریپوی اصلی یه جون به جون‌هام اضافه میکنه :)</li>
</ul>

<br>

<p align="center">
  <strong>📄 تحت مجوز MIT — © ۱۴۰۴ خشایار</strong>
</p>

<p align="center">
  <sub>✨ ساخته شده با ☕ و 💻 توسط <a href="https://github.com/khashayardev">@khashayardev</a></sub>
</p>
