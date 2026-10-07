(function () {
  'use strict';
  var attempts = 0;
  function boot() {
    if (!window.jQuery || !window.EmcoreUI) {
      if (++attempts < 100) { window.setTimeout(boot, 100); return; }
      document.getElementById('bc-message').hidden = false;
      document.getElementById('bc-message').textContent = 'فایل‌های رابط یا jQuery بارگذاری نشده‌اند.';
      return;
    }
    var $ = window.jQuery, UI = window.EmcoreUI, API = '/emcore_api/emcore_business_cards.php';
    var token = '', permissions = {}, countries = [], storageReady = false, maxBytes = 10485760;
    var page = 1, pages = 0, sort = 'id', order = 'desc', rows = {}, listRequest, listGeneration = 0;
    var card = null, busy = false, dirty = false, pauseEditor = false;
    var createRequest = UI.requestId(), uploadRequest = UI.requestId(), uploadPending = null;
    var imageXhr = null, imageUrl = null, imageGeneration = 0, imageCard = null, returnEditor = false;
    function el(id) { return document.getElementById('bc-' + id); }
    function allowed(key) { return Number(permissions['can_' + key]) === 1; }
    function number(value) { return UI.persianDigits(String(value)); }
    function message(id, text, error) { $(el(id)).prop('hidden', !text).attr('class', 'ec-message' + (error ? ' error' : '')).text(text || ''); }
    function error(xhr) { return UI.errorText(xhr) || 'درخواست انجام نشد؛ دوباره تلاش کنید.'; }
    function api(action, data) { return $.ajax({ url: API, type: 'POST', dataType: 'json', data: $.extend({}, data || {}, { action: action }), headers: token ? { 'X-CSRF-Token': token } : {} }); }
    function countryOptions(select, value, empty) {
      var code = $(select), picker = code.data('country-picker');
      if (!picker) {
        var root = $('<span>').addClass('bc-country-picker'), id = (code.attr('id') || 'bc-location-country-' + UI.requestId()) + '-suggestions';
        var input = $('<input>').attr({ type: 'search', role: 'combobox', autocomplete: 'off', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': id, placeholder: empty || 'نام فارسی یا انگلیسی کشور' });
        input.attr('aria-label', code.closest('label').contents().filter(function () { return this.nodeType === 3; }).text().trim() || 'انتخاب کشور');
        var list = $('<span>').attr({ id: id, role: 'listbox', hidden: true }).addClass('bc-country-suggestions'), active = -1;
        code.empty().append($('<option>').val('')); countries.forEach(function (c) { code.append($('<option>').val(c.code)); });
        code.prop('hidden', true).attr({ 'aria-hidden': 'true', tabindex: '-1' }).after(root.append(input, list));
        function close() { list.prop('hidden', true); input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant'); active = -1; }
        function choose(c) { picker.set(c ? c.code : ''); code.trigger('change'); input.focus(); close(); }
        function draw() {
          function normalize(v) { return v.toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک'); }
          var query = normalize(input.val().trim()); list.empty(); active = -1;
          var matches = countries.filter(function (c) { return normalize([c.name_fa, c.name_en, c.code].join(' ')).indexOf(query) !== -1; }).slice(0, 25);
          if (!query) list.append($('<button>').attr({ type: 'button', role: 'option', tabindex: '-1', id: id + '-empty' }).text(empty || 'نامشخص').on('click', function () { choose(null); }));
          matches.forEach(function (c, i) { list.append($('<button>').attr({ type: 'button', role: 'option', id: id + '-' + i, tabindex: '-1' }).text(c.name_fa + ' — ' + c.name_en + ' (' + c.code + ')').on('click', function () { choose(c); })); });
          if (!matches.length) list.append($('<span>').text('کشوری پیدا نشد.'));
          list.prop('hidden', false); input.attr('aria-expanded', 'true');
        }
        input.on('focus', draw).on('input', function () { code.val(''); this.setCustomValidity(this.value.trim() ? 'کشور را از پیشنهادها انتخاب کنید.' : ''); draw(); }).on('keydown', function (e) {
          var buttons = list.find('button');
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (list.prop('hidden')) draw(); buttons = list.find('button'); if (!buttons.length) return; active = (active + (e.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length; buttons.attr('aria-selected', 'false').eq(active).attr('aria-selected', 'true'); input.attr('aria-activedescendant', buttons.eq(active).attr('id')); buttons.eq(active).get(0).scrollIntoView({ block: 'nearest' }); }
          if (e.key === 'Enter' && !list.prop('hidden')) { e.preventDefault(); if (active >= 0) buttons.eq(active).trigger('click'); }
          if (e.key === 'Escape' && !list.prop('hidden')) { e.preventDefault(); e.stopPropagation(); close(); }
        });
        root.on('focusout', function () { window.setTimeout(function () { if (!root.get(0).contains(document.activeElement)) close(); }, 0); });
        picker = { set: function (v) { var c = countries.filter(function (x) { return x.code === v; })[0]; code.val(c ? c.code : ''); input.val(c ? c.name_fa : ''); input.get(0).setCustomValidity(''); close(); } };
        code.data('country-picker', picker).on('change', function () { picker.set(code.val()); });
      }
      picker.set(value || '');
    }
    function collectFilters() {
      return { search: $(el('search')).val(), business_country_code: $(el('country')).val(), location_country: $(el('location-country')).val(), city: $(el('city')).val(), related_unit: $(el('unit')).val(), has_image: $(el('has-image')).val(), has_coordinates: $(el('has-geo')).val(), review_status: $(el('review')).val(), record_origin: $(el('origin')).val(), page: page, page_size: $(el('size')).val(), sort_by: sort, sort_order: order };
    }
    // Native XHR avoids old jQuery's JSON/text conversions of image responses.
    function binary(action, id, fileId, variant, success, failure) {
      var xhr = new XMLHttpRequest(); xhr.open('POST', API, true); xhr.responseType = 'blob';
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300 && /^image\/(jpeg|png)/.test(xhr.getResponseHeader('Content-Type') || '')) success(xhr.response);
        else {
          var reader = new FileReader();
          reader.onload = function () { var text = 'تصویر دریافت نشد.'; try { text = JSON.parse(reader.result).error || text; } catch (ignore) {} failure(text); };
          reader.onerror = function () { failure('تصویر دریافت نشد.'); }; reader.readAsText(xhr.response);
        }
      };
      xhr.onerror = function () { failure('ارتباط با سرور برقرار نشد.'); };
      xhr.send('action=' + encodeURIComponent(action) + '&id=' + encodeURIComponent(id) + (fileId ? '&file_id=' + encodeURIComponent(fileId) : '') + '&variant=' + encodeURIComponent(variant || 'original'));
      return xhr;
    }
    function render(data, generation) {
      rows = {}; $(el('rows')).empty();
      if (!data.length) $(el('rows')).append($('<tr>').append($('<td>').attr('colspan', 10).text('کارت ویزیتی با این فیلترها پیدا نشد.')));
      data.forEach(function (row) {
        rows[row.id] = row;
        var tr = $('<tr>'), pic = $('<td>'), ops = $('<div>').addClass('bc-row-actions');
        pic.text(row.image_id ? 'دارای تصویر' : 'بدون تصویر');
        tr.append(pic);
        row.primary_location = [row.city, row.location_country_name].filter(Boolean).join('، ') || row.primary_address;
        ['contact_name', 'organization_name', 'job_title', 'business_country_name', 'primary_location', 'phones', 'emails', 'related_unit'].forEach(function (key) {
          tr.append($('<td>').toggleClass('bc-ltr', key === 'phones' || key === 'emails').text(row[key] || 'نامشخص'));
        });
        ops.append($('<button>').addClass('ec-btn ec-primary ec-small').text('نمایش کارت ویزیت').prop('disabled', !row.image_id).on('click', function () { showImage(row, this, false); }));
        ops.append($('<button>').addClass('ec-btn ec-small').text(allowed('update') ? 'جزئیات و ویرایش' : 'جزئیات').on('click', function () { openCard(row.id, this); }));
        tr.append($('<td>').append(ops)); $(el('rows')).append(tr);
      });
    }
    function loadList() {
      var generation = ++listGeneration; if (listRequest) listRequest.abort();
      message('message', 'در حال دریافت آرشیو…');
      listRequest = api('list', collectFilters()).done(function (r) {
        if (generation !== listGeneration) return;
        token = r.csrf_token || token; permissions = r.permissions || permissions;
        $(el('new')).prop('hidden', !allowed('create')); render(r.data, generation);
        pages = r.pagination.total_pages; $(el('page')).text(pages ? 'صفحهٔ ' + number(page) + ' از ' + number(pages) : 'بدون نتیجه');
        $(el('prev')).prop('disabled', page <= 1); $(el('next')).prop('disabled', page >= pages);
        $(el('summary')).empty();
        [['total', 'کارت'], ['with_image', 'دارای تصویر'], ['without_image', 'بدون تصویر'], ['with_coordinates', 'دارای موقعیت'], ['needs_review', 'نیازمند بررسی']].forEach(function (pair) {
          $(el('summary')).append($('<p>').text(number(r.summary[pair[0]]) + ' ' + pair[1]));
        });
        message('message', storageReady ? '' : 'مخزن تصویر آماده نیست؛ ثبت و مشاهدهٔ اطلاعات همچنان در دسترس است.', !storageReady);
      }).fail(function (xhr, status) { if (status !== 'abort' && generation === listGeneration) { rows = {}; $(el('rows')).empty(); $(el('summary')).empty(); message('message', error(xhr), true); } });
    }
    function blankCard() { return { contact_points: [], locations: [], sources: [], image: null, review_status: 'needs_review' }; }
    var editor = new UI.Modal(el('editor-overlay'), { beforeClose: function () {
      if (busy && !pauseEditor) return false;
      if (!pauseEditor && dirty && !window.confirm('تغییرات ذخیره نشده‌اند. پنجره بسته شود؟')) return false;
      return true;
    } });
    function clearImage() {
      imageGeneration += 1; if (imageXhr) imageXhr.abort(); imageXhr = null;
      $(el('full-image')).removeAttr('src').prop('hidden', true); if (imageUrl) URL.revokeObjectURL(imageUrl); imageUrl = null;
      $(el('download')).prop('disabled', true);
    }
    var imageModal = new UI.Modal(el('image-overlay'), { beforeClose: function () {
      clearImage(); if (returnEditor) { returnEditor = false; window.setTimeout(function () { editor.open(el('editor-preview')); el('editor-preview').focus(); }, 0); } return true;
    } });
    function showImage(row, trigger, fromEditor) {
      clearImage(); imageCard = row; returnEditor = fromEditor;
      if (fromEditor) { pauseEditor = true; editor.close(); pauseEditor = false; }
      $(el('image-title')).text([row.contact_name, row.organization_name].filter(Boolean).join(' / ') || 'کارت ویزیت ' + number(row.id));
      $(el('image-message')).text('در حال بارگذاری تصویر…').prop('hidden', false);
      imageModal.open(trigger); var generation = imageGeneration;
      imageXhr = binary('preview_image', row.id, row.image_id || (row.image && row.image.id), 'original', function (blob) {
        if (generation !== imageGeneration || !imageModal.isOpen()) return;
        imageUrl = URL.createObjectURL(blob); $(el('full-image')).attr('src', imageUrl).prop('hidden', false);
        $(el('image-message')).prop('hidden', true); $(el('download')).prop('disabled', false);
      }, function (text) { if (generation === imageGeneration && imageModal.isOpen()) $(el('image-message')).text(text).prop('hidden', false); });
    }
    function addPoint(p) {
      p = p || { kind: 'phone', raw_value: '', label: '' };
      var root = $('<div>').addClass('bc-point-row'), kind = $('<select>').attr('aria-label', 'نوع راه ارتباطی');
      [['phone', 'تلفن'], ['mobile', 'همراه'], ['fax', 'فکس'], ['email', 'ایمیل'], ['website', 'وب‌سایت'], ['messenger', 'پیام‌رسان'], ['other', 'متن اصلی / سایر']].forEach(function (pair) { kind.append($('<option>').val(pair[0]).text(pair[1])); });
      root.append(kind.val(p.kind).addClass('bc-point-kind'), $('<input>').addClass('bc-point-label').attr({ 'aria-label': 'برچسب', maxlength: 255 }).val(p.label || ''), $('<input>').addClass('bc-point-value bc-ltr').attr({ 'aria-label': 'مقدار راه ارتباطی', maxlength: 1000 }).val(p.raw_value));
      root.append($('<button>').attr('type', 'button').addClass('ec-btn ec-danger bc-remove').text('حذف').on('click', function () { root.remove(); markDirty(); }));
      $(el('contact-points')).append(root);
    }
    function addLocation(loc) {
      loc = loc || {}; var root = $('<div>').addClass('bc-location-row').data('source', loc), grid = $('<div>').addClass('bc-location-grid');
      [['label', 'عنوان محل'], ['region_name', 'استان / ناحیه'], ['city', 'شهر'], ['postal_code', 'کدپستی'], ['latitude', 'عرض جغرافیایی'], ['longitude', 'طول جغرافیایی']].forEach(function (pair) {
        var input = $('<input>').addClass('bc-location-' + pair[0]).val(loc[pair[0]] || '').attr('maxlength', pair[0] === 'latitude' || pair[0] === 'longitude' ? 30 : pair[0] === 'postal_code' ? 100 : 255);
        if (pair[0] === 'latitude' || pair[0] === 'longitude') input.addClass('bc-ltr'); grid.append($('<label>').text(pair[1]).append(input));
      });
      var country = $('<select>').addClass('bc-location-country');
      var accuracy = $('<select>').addClass('bc-location-accuracy'); [['unknown', 'نامشخص'], ['address', 'نشانی / ساختمان'], ['street', 'خیابان / محله'], ['city', 'شهر / ناحیه']].forEach(function (p) { accuracy.append($('<option>').val(p[0]).text(p[1])); }); accuracy.val(loc.accuracy || 'unknown');
      grid.append($('<label>').text('کشور محل نشانی').append(country), $('<label>').text('دقت موقعیت').append(accuracy));
      countryOptions(country, loc.country_code);
      root.append(grid, $('<label>').text('نشانی').append($('<textarea>').addClass('bc-location-address').attr('maxlength', 20000).val(loc.address || '')));
      root.append($('<label>').text('توضیح منبع موقعیت').append($('<textarea>').addClass('bc-location-source_note').attr('maxlength', 10000).val(loc.source_note || '')));
      root.append($('<button>').attr('type', 'button').addClass('ec-btn ec-danger bc-remove').text('حذف نشانی').on('click', function () { root.remove(); markDirty(); })); $(el('locations')).append(root);
    }
    function controls() {
      var write = card && card.id ? allowed('update') : allowed('create');
      $(el('editor-form')).find('input,textarea,select').prop('disabled', busy || !write);
      $(el('save')).prop('hidden', !write).prop('disabled', busy || !dirty);
      $(el('add-contact')).add(el('add-location')).prop('hidden', !write).prop('disabled', busy);
      $(el('editor-form')).find('.bc-remove').prop('hidden', !write).prop('disabled', busy);
      $(el('delete')).prop('hidden', !card || !card.id || !allowed('delete')).prop('disabled', busy);
      $(el('image-file')).prop('disabled', busy || !card || !card.id || !allowed('update') || !storageReady);
      $(el('upload')).text(card && card.image ? 'جایگزینی تصویر' : 'افزودن تصویر').prop('disabled', busy || dirty || !card || !card.id || !allowed('update') || !storageReady || !el('image-file').files.length);
      $(el('delete-image')).prop('hidden', !card || !card.image || !allowed('delete')).prop('disabled', busy || dirty);
      $(el('editor-preview')).prop('disabled', busy || !card || !card.image);
      $(el('duplicates-button')).prop('disabled', busy);
    }
    function markDirty() { dirty = true; controls(); }
    function fillCard(data) {
      card = data; dirty = false; uploadRequest = UI.requestId(); uploadPending = null; $(el('image-file')).val('');
      [['contact-name', 'contact_name'], ['organization-name', 'organization_name'], ['job-title', 'job_title'], ['business-country', 'business_country_code'], ['related-unit', 'related_unit'], ['source-category', 'source_category'], ['review-status', 'review_status'], ['activity', 'activity'], ['notes', 'notes']].forEach(function (pair) { $(el(pair[0])).val(card[pair[1]] || ''); });
      countryOptions(el('business-country'), card.business_country_code);
      $(el('contact-points')).empty(); card.contact_points.forEach(addPoint); $(el('locations')).empty(); card.locations.forEach(addLocation);
      $(el('source-text')).empty(); (card.sources || []).forEach(function (s) { $(el('source-text')).append($('<pre>').addClass('bc-source-block').text(s.raw_text)); });
      $(el('sources')).prop('hidden', !(card.sources || []).length).prop('open', false);
      $(el('image-state')).text(card.id ? card.image ? 'تصویر جاری: ' + card.image.original_filename : 'این کارت تصویر ندارد.' : 'برای بارگذاری تصویر، ابتدا اطلاعات کارت را ذخیره کنید.');
      $(el('duplicates')).empty(); $(el('editor-title')).text(card.id ? 'کارت ویزیت ' + number(card.id) : 'ثبت کارت ویزیت'); controls();
    }
    function openCard(id, trigger) {
      if (id) api('get', { id: id }).done(function (r) { fillCard(r.data); message('editor-message', ''); editor.open(trigger); checkDuplicates(); }).fail(function (xhr) { message('message', error(xhr), true); });
      else { createRequest = UI.requestId(); fillCard(blankCard()); dirty = true; controls(); message('editor-message', ''); editor.open(trigger); }
    }
    function payload() {
      var data = {};
      [['contact-name', 'contact_name'], ['organization-name', 'organization_name'], ['job-title', 'job_title'], ['business-country', 'business_country_code'], ['related-unit', 'related_unit'], ['source-category', 'source_category'], ['review-status', 'review_status'], ['activity', 'activity'], ['notes', 'notes']].forEach(function (pair) { data[pair[1]] = $(el(pair[0])).val(); });
      var points = []; $(el('contact-points')).children().each(function () { points.push({ kind: $(this).find('.bc-point-kind').val(), label: $(this).find('.bc-point-label').val(), raw_value: $(this).find('.bc-point-value').val() }); }); data.contact_points = JSON.stringify(points);
      var locations = []; $(el('locations')).children().each(function () {
        var root = $(this), loc = {}, original = root.data('source');
        ['label', 'country', 'region_name', 'city', 'postal_code', 'latitude', 'longitude', 'accuracy', 'address', 'source_note'].forEach(function (key) { loc[key === 'country' ? 'country_code' : key] = root.find('.bc-location-' + key).val(); });
        loc.source_urls = original.source_urls || []; locations.push(loc);
      }); data.locations = JSON.stringify(locations); return data;
    }
    function checkDuplicates() {
      var currentCard = card, p = payload(); if (card && card.id) p.id = card.id;
      api('duplicate_candidates', p).done(function (r) {
        if (card !== currentCard || !editor.isOpen()) return;
        $(el('duplicates')).empty(); if (!r.data.length) return;
        $(el('duplicates')).append($('<h3>').text('موارد مشابه؛ ثبت اطلاعات مجاز است'));
        r.data.forEach(function (candidate) {
          var line = $('<p>').text('کارت ' + number(candidate.id) + ': ' + [candidate.contact_name, candidate.organization_name].filter(Boolean).join(' / ') + '، ' + candidate.reasons.join('؛ '));
          line.append($('<button>').attr('type', 'button').addClass('ec-btn ec-small').text('مشاهدهٔ کارت مشابه').on('click', function () { if (editor.close() !== false) openCard(candidate.id, el('new')); }));
          $(el('duplicates')).append(line);
        });
      }).fail(function (xhr) { message('editor-message', error(xhr), true); });
    }
    $(el('editor-form')).on('input change', 'input,textarea,select', markDirty).on('submit', function (e) {
      e.preventDefault(); if (busy) return; var data = payload(), isNew = !card.id;
      if (isNew) data.request_id = createRequest; else { data.id = card.id; data.lock_version = card.lock_version; }
      busy = true; controls(); api(isNew ? 'create' : 'update', data).done(function (r) {
        busy = false; fillCard(r.data); message('editor-message', 'اطلاعات ذخیره شد.'); loadList(); checkDuplicates();
      }).fail(function (xhr) { message('editor-message', error(xhr), true); }).always(function () { busy = false; controls(); });
    });
    $(el('image-file')).on('change', function () { uploadRequest = UI.requestId(); uploadPending = null; controls(); });
    $(el('upload')).on('click', function () {
      if (busy || dirty || !card.id || !el('image-file').files.length) return;
      var file = el('image-file').files[0]; if (file.size > maxBytes) { message('editor-message', 'اندازه تصویر بیش از حد مجاز است.', true); return; }
      // Stable action/payload survive a lost upload response until selection changes.
      if (!uploadPending) uploadPending = { action: card.image ? 'replace_image' : 'upload_image', version: card.lock_version };
      var data = new FormData(); data.append('action', uploadPending.action); data.append('id', card.id); data.append('lock_version', uploadPending.version); data.append('request_id', uploadRequest); data.append('file', file);
      busy = true; controls(); $(el('upload-progress')).prop('hidden', false).val(0);
      $.ajax({ url: API, type: 'POST', data: data, processData: false, contentType: false, dataType: 'json', headers: { 'X-CSRF-Token': token }, xhr: function () {
        var xhr = $.ajaxSettings.xhr(); if (xhr.upload) xhr.upload.addEventListener('progress', function (e) { if (e.lengthComputable) $(el('upload-progress')).val(e.loaded * 100 / e.total); }); return xhr;
      } }).done(function (r) { busy = false; fillCard(r.data); message('editor-message', 'تصویر ذخیره شد.'); loadList(); checkDuplicates(); }).fail(function (xhr) { message('editor-message', error(xhr), true); }).always(function () { busy = false; $(el('upload-progress')).prop('hidden', true); controls(); });
    });
    function remove(action) {
      if (busy || !card.id || !window.confirm(action === 'delete' ? 'این کارت از آرشیو حذف شود؟' : 'تصویر جاری حذف شود؟')) return;
      busy = true; controls(); api(action, { id: card.id, lock_version: card.lock_version }).done(function (r) {
        busy = false; dirty = false; if (action === 'delete') editor.close(); else fillCard(r.data); loadList(); message('editor-message', 'حذف انجام شد.');
      }).fail(function (xhr) { message('editor-message', error(xhr), true); }).always(function () { busy = false; controls(); });
    }
    $(el('delete')).on('click', function () { remove('delete'); }); $(el('delete-image')).on('click', function () { remove('delete_image'); });
    $(el('add-contact')).on('click', function () { addPoint(); markDirty(); }); $(el('add-location')).on('click', function () { addLocation(); markDirty(); });
    $(el('duplicates-button')).on('click', checkDuplicates); $(el('editor-close')).on('click', function () { editor.close(); });
    $(el('editor-preview')).on('click', function () { showImage(card, this, true); }); $(el('image-close')).on('click', function () { imageModal.close(); });
    $(el('download')).on('click', function () {
      var generation = imageGeneration; $(el('download')).prop('disabled', true);
      imageXhr = binary('download_image', imageCard.id, imageCard.image_id || (imageCard.image && imageCard.image.id), 'original', function (blob) {
        if (generation !== imageGeneration) return;
        var url = URL.createObjectURL(blob), link = document.createElement('a'); link.href = url; link.download = 'card-' + imageCard.id + (blob.type === 'image/png' ? '.png' : '.jpg'); document.body.appendChild(link); link.click(); link.remove(); window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000); $(el('download')).prop('disabled', false);
      }, function (text) { if (generation === imageGeneration) { $(el('image-message')).text(text).prop('hidden', false); $(el('download')).prop('disabled', false); } });
    });
    $(el('new')).on('click', function () { openCard(null, this); });
    $(el('filters')).on('submit', function (e) { e.preventDefault(); page = 1; loadList(); });
    $(el('reset')).on('click', function () { el('filters').reset(); countryOptions(el('country'), '', 'همه کشورها'); countryOptions(el('location-country'), '', 'همه کشورها'); page = 1; sort = 'id'; order = 'desc'; $(el('table')).find('th').removeAttr('aria-sort'); loadList(); });
    $(el('prev')).on('click', function () { if (page > 1) { page--; loadList(); } }); $(el('next')).on('click', function () { if (page < pages) { page++; loadList(); } }); $(el('size')).on('change', function () { page = 1; loadList(); });
    $(el('table')).find('th[data-sort] button').on('click', function () { var th = $(this).parent(), key = th.data('sort'); order = sort === key && order === 'asc' ? 'desc' : 'asc'; sort = key; page = 1; $(el('table')).find('th').removeAttr('aria-sort'); th.attr('aria-sort', order === 'asc' ? 'ascending' : 'descending'); loadList(); });
    window.addEventListener('beforeunload', clearImage);
    api('lookups').done(function (r) {
      token = r.csrf_token; permissions = r.permissions; countries = r.data.countries; storageReady = r.data.storage_ready; maxBytes = r.data.max_upload_bytes;
      countryOptions(el('country'), '', 'همه کشورها'); countryOptions(el('location-country'), '', 'همه کشورها'); countryOptions(el('business-country'));
      r.data.units.forEach(function (unit) { $(el('unit')).append($('<option>').val(unit).text(unit)); });
      $(el('image-limit')).text('JPEG یا PNG، حداکثر ' + number(Math.round(maxBytes / 1048576)) + ' مگابایت'); loadList();
    }).fail(function (xhr) { message('message', error(xhr), true); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
