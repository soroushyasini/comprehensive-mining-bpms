(function ($, UI) {
    'use strict';
    const root = $('[data-emcore-contracts]').last();
    if (!root.length || !UI || root.data('contractsMounted')) return;
    root.data('contractsMounted',true);
    const endpoint = '/emcore_api/emcore_contracts.php';
    const body = root.find('.ct-content');
    const nav = root.find('.ct-nav');
    const box = root.find('.ct-dialog-body');
    const overlay = root.find('.ct-overlay');
    const modal = new UI.Modal(overlay, { beforeClose: () => {
        if (uploadBusy || formSaving) return false;
        if(pendingUploads && pendingUploads()>0 && !window.confirm('هنوز فایل‌هایی باقی مانده‌اند. فایل‌های موفق حفظ شده‌اند؛ با بستن پنجره، برای ادامه باید فایل‌های باقی‌مانده را دوباره انتخاب کنید. خارج می‌شوید؟'))return false;
        pendingUploads=null;dialogEpoch++;clearWidgets();
        return true;
    }});
    let caps = {}, lookups = {}, token, today, active = 'list', caseId, caseData, tab = 'documents';
    let generation = 0, uploadBusy = false, filters = {}, listOffset = 0, actionOffset = 0, manifestHash = '', importOffset = 0;
    let widgets = [], formSaving = false, advancedFiltersOpen = false, candidatesOpen = false;
    let lastListFocus = null, storageReady=false, historyCategory=null, dialogEpoch=0, pendingUploads=null;
    let reminderMode='open',reminderFilters={state:'open'};
    const status = {
        unknown:'نامشخص', draft:'پیش‌نویس', awaiting_signature:'در انتظار امضا', active:'فعال', suspended:'تعلیق‌شده',
        terminated:'خاتمه‌یافته', closed:'بسته‌شده', unsigned:'بدون امضا', partial:'امضای ناقص', signed:'امضای کامل',
        provisional:'موقت', needs_review:'نیازمند بازبینی', verified:'تأییدشده', expired:'گذشته از پایان',
        current:'جاری', not_started:'شروع‌نشده', archived:'بایگانی‌شده', deleted:'حذف‌شده', merged:'ادغام‌شده', all:'همه',
        open:'باز', in_progress:'در حال انجام', done:'انجام‌شده', cancelled:'لغوشده',
        low:'کم', normal:'عادی', high:'زیاد', confirmed:'تأیید مقدار', not_stated:'در سند مشخص نشده',
        pending:'در انتظار یا نیازمند بررسی', available:'موجود', missing:'ناموجود', digital_only:'فقط دیجیتال',
        fixed:'ثابت', unit_rate:'نرخ واحد', ceiling:'سقف تعهد', ours:'خودی', other:'طرف مقابل / سایر',
        company:'شرکت', person:'شخص', manual:'ثبت دستی', main:'قرارداد اصلی', amendment:'الحاقیه یا تمدید',
        attachment:'پیوست', payment:'مدرک پرداخت', guarantee:'تضمین', correspondence:'مکاتبه', identity:'مدرک هویتی',
        minutes:'صورت‌جلسه', reference:'مرجع', obligation:'تعهد', insurance:'بیمه', fulfilled:'انجام‌شده', released:'آزاد شده',
        end:'پایان قرارداد', notice:'مهلت اخطار', action:'اقدام', imported:'واردشده', blocked:'مسدود', ready:'آماده',
        quarantine:'قرنطینه', container:'فایل آرشیو', ungrouped:'بدون پرونده',
        IRR:'ریال', IRT:'تومان', USD:'دلار آمریکا', EUR:'یورو'
    };
    const label = {
        title:'عنوان', official_number:'شمارهٔ رسمی', contract_type:'نوع قرارداد', subject:'موضوع', description:'شرح',
        owner_uid:'مسئول', unit_name:'واحد مسئول', project_name:'پروژه', site_name:'محل اجرا', signed_fa:'تاریخ انعقاد',
        starts_fa:'تاریخ شروع', ends_fa:'تاریخ پایان', notice_fa:'مهلت اخطار', legal_status:'وضعیت قرارداد',
        signature_status:'وضعیت امضا', review_status:'وضعیت بازبینی', amount:'مبلغ', currency:'واحد پول',
        amount_type:'مبنای مبلغ', quantity_unit:'واحد مقدار', tax_note:'توضیح مالیات', payment_terms:'شرایط پرداخت',
        alert_days:'آستانهٔ یادآوری (روز)', original_location:'محل اصل', archive_code:'کد بایگانی', custodian:'نگهدارندهٔ اصل',
        original_status:'وضعیت اصل', archive_notes:'توضیحات بایگانی', retention_review_fa:'بازبینی نگهداری',
        hold_reason:'دلیل منع امحا', reason:'دلیل تغییر', side:'سمت طرف', kind:'نوع', name:'نام',
        role_name:'نقش', national_id:'شناسهٔ ملی', registration_number:'شمارهٔ ثبت', address:'نشانی', reference_id:'مرجع',
        category_id:'دسته', sort_order:'ترتیب نمایش', is_active:'فعال', status:'وضعیت', due_fa:'موعد', priority:'اولویت',
        completed_fa:'تاریخ انجام', happened_fa:'تاریخ وقوع', result:'نتیجه', document_id:'مدرک',
        amendment_id:'الحاقیهٔ مرتبط', obligation_id:'تعهد یا تضمین مرتبط', action_id:'اقدام مرتبط',
        role_code:'نقش مدرک', change_note:'توضیح نسخه', number:'شماره', basis_version_id:'نسخهٔ مبنا',
        effective_fa:'تاریخ اثر', corrects_id:'اصلاح الحاقیهٔ پیشین', party_id:'طرف متعهد', issuer:'صادرکننده',
        notes:'یادداشت', target_id:'پروندهٔ مقصد', manifest_hash:'شناسهٔ فهرست آماده‌شده', id:'شناسه'
    };
    const opts = keys => keys.split(' ').map(k => [k,status[k] || k]);
    const names = rows => rows.map(r => [r.id,r.name || r.title]);
    const users = () => lookups.users.map(u => [u.uid,u.name.trim() || u.username]);
    const owner = uid => (users().find(pair => pair[0] === uid) || [null,uid || ''])[1];
    const faToday = () => UI.todayFromGregorian(today);
    const value = (v, map = false) => v === null || v === undefined || v === '' ? '—' : (map && status[v] ? status[v] : String(v));
    const code = id => 'CTR-' + String(id).padStart(8,'0');
    function message(text, error = false) {
        root.find('.ct-message').attr('data-error', String(error)).text(text || '');
    }
    function dialogError(text) { root.find('.ct-dialog-message').text(text || ''); }
    function errorText(e) {
        if (e && e.responseJSON && e.responseJSON.error) return e.responseJSON.error;
        if (e && e.message) return e.message;
        return 'ارتباط با سامانه برقرار نشد.';
    }
    function api(action, payload = {}, file = null, onProgress) {
        const data = new FormData();
        data.append('action', action);
        data.append('payload', JSON.stringify(payload));
        if (file) data.append('file', file);
        const options = { url:endpoint, method:'POST', data, processData:false, contentType:false, dataType:'json',
            headers:token ? {'X-CSRF-Token':token} : {} };
        if (onProgress) options.xhr = function () {
            const xhr = $.ajaxSettings.xhr();
            xhr.upload.addEventListener('progress',onProgress);
            return xhr;
        };
        return $.ajax(options);
    }
    async function get(action, payload) {
        const response = await api(action,payload);
        token = response.csrf_token || token;
        today = response.today || today;
        if(typeof response.storage_ready==='boolean')storageReady=response.storage_ready;
        caps = response.permissions || caps;
        return response.data;
    }
    function write(action, payload) { return api(action, {...payload,request_id:payload.request_id || UI.requestId()}); }
    function button(text, handler, css = '') {
        return $('<button type="button">').addClass('ec-btn ' + css).text(text).on('click',function () {
            const trigger = this;
            Promise.resolve().then(() => handler(trigger)).catch(e => modal.isOpen() ? dialogError(errorText(e)) : message(errorText(e),true));
        });
    }
    function heading(text) { return $('<h2 class="ct-heading">').text(text); }
    function sectionHead(title, actions) {
        return $('<div class="ct-section-head">').append(heading(title),actions);
    }
    function badge(key, text) {
        const tone = ['verified','active','current','imported','done'].includes(key) ? 'positive'
            : ['provisional','needs_review','today','upcoming','quarantine'].includes(key) ? 'attention'
            : ['overdue','expired','blocked','deleted'].includes(key) ? 'danger' : 'neutral';
        return $('<span class="ct-badge">').attr('data-tone',tone).text(text || value(key,true));
    }
    function bidi(text, direction='ltr') {
        return $('<bdi class="ct-token">').attr('dir',direction).text(value(text));
    }
    function emptyState(title, description) {
        return $('<div class="ct-empty">').append($('<strong>').text(title),description ? paragraph(description) : null);
    }
    function openContractList(nextFilters={}) {
        filters=nextFilters;
        listOffset=0;
        lastListFocus=null;
        return navigate('list');
    }
    function paragraph(text) { return $('<p>').text(text); }
    function toolbar(...buttons) { return $('<div class="ct-buttons">').append(buttons.filter(Boolean)); }
    function clearWidgets() {
        widgets.forEach(widget => widget.destroy());
        widgets = [];
    }
    function openDialog(title, trigger) {
        dialogEpoch++;pendingUploads=null;
        clearWidgets();
        box.empty();
        dialogError('');
        root.find('#ct-dialog-title').text(title);
        if (!modal.isOpen()) modal.open(trigger);
        else overlay.find('[role=dialog]').trigger('focus');
        box.scrollTop(0);
        return box;
    }
    function field(spec, current) {
        const [name,type = 'text',choices = [],required = false,text] = spec;
        const caption = text || label[name] || name;
        const wrapper = $(type==='participant' ? '<div class="ct-field">' : '<label class="ct-field">')
            .append($('<span>').text(caption));
        if (type==='area' || name==='title' && required) wrapper.addClass('ct-field-wide');
        let input;
        if (type === 'participant') {
            const host=$('<div>');
            const stored=$('<input type="hidden">').attr('name',name).val(current || '');
            const picker=new UI.ParticipantPicker(host,{
                label:caption,allowExternal:false,multiple:false,
                search:term=>{
                    const query=term.trim().toLowerCase();
                    const found=lookups.users.filter(user=>((user.name || '')+' '+user.username).toLowerCase().includes(query));
                    return $.Deferred().resolve({data:found.slice(0,25).map(user=>({
                        usr_uid:user.uid,name_snapshot:user.name.trim() || user.username,username:user.username
                    }))}).promise();
                },
                onChange:selection=>{
                    stored.val(selection.length ? selection[0].usr_uid : '');
                    host.find('[role=combobox]')[0].setCustomValidity(required && !selection.length ? 'مسئول را انتخاب کنید.' : '');
                }
            });
            if (current) picker.set([{usr_uid:current,name_snapshot:owner(current)}]);
            if (required && !current) host.find('[role=combobox]')[0].setCustomValidity('مسئول را انتخاب کنید.');
            widgets.push(picker);
            wrapper.append(host,stored);
            return wrapper;
        }
        if (type === 'select') {
            input = $('<select>').append($('<option>').val('').text('انتخاب کنید'));
            choices.forEach(([key,caption]) => input.append($('<option>').val(String(key)).text(caption)));
            if (current !== null && current !== undefined && current !== '' && !choices.some(([key]) => String(key) === String(current))) {
                input.append($('<option>').val(String(current)).text(String(current)));
            }
        } else if (type === 'area') input = $('<textarea>');
        else input = $('<input>').attr('type',type === 'date' ? 'text' : type);
        input.attr('name',name).prop('required',required);
        if (type === 'number') input.attr({min:0,step:1});
        if (name==='alert_days') input.attr({min:1,max:365});
        if (name==='amount') input.attr({inputmode:'decimal',dir:'ltr'});
        if (name==='currency') input.attr({placeholder:'IRR / IRT',dir:'ltr',maxlength:12});
        if (name==='official_number' || name==='manifest_hash') input.attr('dir','ltr');
        if (name==='manifest_hash') input.attr({spellcheck:'false',autocomplete:'off',maxlength:64});
        if (type === 'date') {
            input.attr({placeholder:'سال/ماه/روز',inputmode:'text',autocomplete:'off'}).addClass('ct-dates');
            wrapper.append($('<span class="ct-date-host">').append(input));
            widgets.push(new UI.DatePicker(input,{today:faToday}));
        } else wrapper.append(input);
        if (type === 'checkbox') input.prop('checked',current === true || Number(current) === 1);
        else if (type !== 'file') input.val(current == null ? '' : String(current));
        return wrapper;
    }
    function fieldsForm(specs, row) {
        const form = $('<form>');
        return form.append(fieldGrid(specs,row));
    }
    function fieldGrid(specs,row) {
        return $('<div class="ct-fields">').append(specs.map(spec=>field(spec,row[spec[0]])));
    }
    function readForm(form) {
        const values = {};
        form.find('[name]').each(function () {
            if (!this.disabled && this.type !== 'file') values[this.name] = this.type === 'checkbox' ? this.checked : this.value;
        });
        return values;
    }
    function submit(form, fn, text = 'ذخیره', refresh = true) {
        const save = $('<button type="submit" class="ec-btn ec-primary">').text(text);
        const cancel = button('انصراف',()=>modal.close());
        const close = overlay.find('.ct-close');
        form.append(toolbar(save,cancel).addClass('ct-form-footer')).on('submit',async e => {
            e.preventDefault();
            e.stopPropagation();
            if (formSaving) return;
            formSaving=true;
            save.text('در حال پردازش…');
            save.add(cancel).add(close).prop('disabled',true);
            dialogError('');
            let succeeded=false;
            try {
                await fn(readForm(form));
                succeeded=true;
            } catch (err) {
                dialogError(errorText(err));
            } finally {
                formSaving=false;
                save.text(text).add(cancel).add(close).prop('disabled',false);
            }
            if (succeeded && refresh) { modal.close(); message('تغییر ذخیره شد.'); await render(); }
        });
        return form;
    }
    function editor(title, specs, row, operation, extra, trigger, callback) {
        openDialog(title,trigger);
        const form = fieldsForm(specs,row);
        box.append(submit(form, async values => {
            const response = await write(operation,{...extra,...values});
            if (callback) callback(response.data);
        }));
    }
    function askReason(title, operation, payload, trigger) {
        editor(title,[['reason','area',[],true]],{},operation,payload,trigger);
    }
    function table(headers, rows, options={}) {
        if (!rows.length) return emptyState(options.emptyTitle || 'موردی برای نمایش نیست.',options.emptyDescription);
        const t = $('<table role="table">').append($('<thead role="rowgroup">').append($('<tr role="row">')
            .append(headers.map(h => $('<th scope="col" role="columnheader">').text(h)))));
        const tbody = $('<tbody role="rowgroup">');
        for (const row of rows) {
            const tr = $('<tr role="row">');
            row.forEach((item,i)=>{
                const td=$('<td role="cell">');
                if (options.cards && i>0) td.append($('<span class="ct-cell-label" aria-hidden="true">').text(headers[i]));
                td.append($('<div class="ct-cell-value">').append(item && item.jquery ? item : document.createTextNode(value(item))));
                tr.append(td);
            });
            tbody.append(tr);
        }
        const wrap=$('<div class="ct-scroll" tabindex="0" role="region">').attr('aria-label',options.name || 'جدول داده‌ها');
        if (options.cards) wrap.addClass('ct-card-table').removeAttr('tabindex role aria-label');
        return wrap.append(t.append(tbody));
    }
    function details(target,row,keys) {
        const items=$('<dl class="ct-metrics">');
        for (const key of keys) {
            const caption=$('<dt>').text(label[key] || key);
            const answer=$('<dd>');
            if (key.endsWith('_fa')) answer.append(bidi(row[key] ? UI.persianDigits(row[key]) : null));
            else if (key==='official_number') answer.append(bidi(row[key],'auto'));
            else answer.text(key==='owner_uid' ? owner(row[key]) : value(row[key],true));
            items.append($('<div class="ct-card">').append(caption,answer));
        }
        target.append(items);
    }
    function pager(target,offset,more,load,size = 25) {
        if (!offset && !more) return;
        target.append($('<nav class="ct-pager" aria-label="صفحه‌بندی">').append(
            offset>0 ? button('صفحهٔ قبل',()=>load(Math.max(0,offset-size))) : null,
            $('<span>').text('صفحهٔ '+UI.persianDigits(Math.floor(offset/size)+1)),
            more ? button('صفحهٔ بعد',()=>load(offset+size)) : null));
    }
    function transfer(action,payload,preview=false) {
        const form = $('<form method="post">').attr('action',endpoint).attr('target',preview?'_blank':'_self');
        form.append($('<input type="hidden" name="action">').val(action),$('<input type="hidden" name="payload">').val(JSON.stringify(payload)));
        $(document.body).append(form);
        form[0].submit();
        form.remove();
    }
    function navigate(page,id) {
        if (page === 'case' && String(caseId) !== String(id)){tab = 'documents';historyCategory=null;}
        active = page; caseId = id || null;
        if (page === 'mine' || page === 'actions') actionOffset = 0;
        message('');
        return render(0,0,true);
    }
    function caseLink(id,text) {
        return button(text || code(id),()=>navigate('case',id),'ct-text-link')
            .attr('dir',text ? 'auto' : 'ltr');
    }
    const iconPaths={folder:'M3 7h7l2 2h9v11H3z M3 7V4h6l3 3',archive:'M4 9h16v12H4z M3 3h18v6H3z M9 13h6',plus:'M12 5v14 M5 12h14',search:'M10 3a7 7 0 1 0 0 14a7 7 0 0 0 0-14 M15 15l6 6',file:'M5 3h9l5 5v13H5z M14 3v6h5 M8 13h8 M8 17h6',download:'M12 3v12 M7 10l5 5 5-5 M4 17v4h16v-4',eye:'M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7 M12 9a3 3 0 1 0 0 6a3 3 0 0 0 0-6',clock:'M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18 M12 7v5l4 2',back:'M4 12h16 M14 6l6 6-6 6',check:'M4 12l5 5 11-11',clip:'M8 12l6-6a3 3 0 0 1 4 4l-8 8a5 5 0 0 1-7-7l8-8',edit:'M4 16L16 4l4 4L8 20H4z M13 7l4 4',info:'M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18 M12 11v6 M12 7h.1'};
    function icon(name) { return $('<svg class="ct-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="'+iconPaths[name]+'"/></svg>'); }
    function iconButton(text,handler,name,css='') { return button(text,handler,css).prepend(icon(name)); }
    function menu(title,items) {
        const container=$('<details class="ct-menu">').append($('<summary>').text(title));
        const choices=$('<div class="ct-menu-options">');
        items.filter(Boolean).forEach(([text,fn])=>choices.append(button(text,t=>{container.prop('open',false);return fn(t);},'ct-menu-item')));
        return container.append(choices);
    }
    function fileInput(required=false,multiple=true) {
        const input=$('<input type="file" name="files">').attr({accept:lookups.extensions.map(e=>'.'+e).join(','),'aria-label':'انتخاب فایل'}).prop({required,multiple});
        const names=$('<ul class="ct-upload-names">');
        input.on('change',()=>{names.empty();Array.from(input[0].files).forEach(file=>names.append($('<li>').text(file.name+' · '+UI.fileSize(file.size))));});
        const picker=$('<label class="ct-file-picker ec-btn">').append($('<span>').text(multiple?'انتخاب فایل‌ها':'انتخاب فایل'),input);
        const host=$('<div class="ct-upload-zone">').append(icon('clip'),paragraph('فایل‌های موردنظر را انتخاب کنید.'),picker,names);
        return {input,host};
    }
    function validateFiles(input) {
        const files=Array.from(input[0].files);
        for (const file of files) {
            if (file.size>lookups.max_upload_bytes) throw new Error('حداکثر اندازهٔ هر فایل ۵۰ مگابایت است.');
            if (!lookups.extensions.includes(file.name.split('.').pop().toLowerCase())) throw new Error('قالب فایل مجاز نیست: '+file.name);
        }
        return files;
    }
    async function showFile(doc,version,trigger) {
        const link={contract_id:caseId,document_id:doc.id,version_id:version.id};
        if (!/^(application\/pdf|image\/(png|jpeg|bmp|x-ms-bmp))$/.test(version.mime_type)) return transfer('download',link);
        openDialog(doc.title,trigger);
        const epoch=dialogEpoch;
        box.append(paragraph('در حال دریافت پیش‌نمایش…'));
        const data=new FormData();data.append('action','download');data.append('payload',JSON.stringify({...link,preview:true}));
        const response=await fetch(endpoint,{method:'POST',body:data,credentials:'same-origin'});
        if (!response.ok) {
            let text='دریافت پیش‌نمایش ناموفق بود.';
            try { text=(await response.json()).error || text; } catch (_) {}
            throw new Error(text);
        }
        const blob=await response.blob();
        if (epoch!==dialogEpoch || !modal.isOpen()) return;
        const url=URL.createObjectURL(blob);
        widgets.push({destroy:()=>URL.revokeObjectURL(url)});
        const area=$('<div class="ct-preview-area">');
        if (version.mime_type==='application/pdf') area.append($('<iframe>').attr({src:url,title:'پیش‌نمایش '+doc.title}));
        else area.append($('<img>').attr({src:url,alt:doc.title}));
        box.empty().append(area,paragraph('اگر پیش‌نمایش در مرورگر شما باز نمی‌شود، فایل را دریافت کنید.').addClass('ct-field-note'),
            toolbar(iconButton('دریافت فایل',()=>transfer('download',link),'download','ec-primary'),button('بستن',()=>modal.close())));
    }
    // Keep each retry's payload and request ID unchanged, including a lost HTTP response.
    function queueEditor(form,input,saveRecord,payloadForFile,finish) {
        let saved=null, prepared=false, frozen=null;
        const createRequest=UI.requestId(), payloads=new Map();
        const progress=$('<p role="status" class="ct-upload-progress">');
        const save=$('<button type="submit" class="ec-btn ec-primary">').text('ذخیره');
        const cancel=button('بستن',()=>modal.close());
        const close=overlay.find('.ct-close');
        const queue=new UI.UploadQueue({
            send:(file,requestId,observer)=>{
                if (!payloads.has(requestId)) payloads.set(requestId,{...payloadForFile(saved,file),request_id:requestId});
                return api('document_upload',payloads.get(requestId),file,observer);
            },
            onSuccess:response=>{if(response.data.history)saved=response.data.history;},
            onProgress:(percent,name,index,total)=>progress.text(UI.persianDigits(index+1)+' از '+UI.persianDigits(total)+' · '+name+' · '+UI.persianDigits(Math.floor(percent))+'٪'),
            onError:error=>{
                uploadBusy=false;save.add(cancel).add(close).prop('disabled',false);save.text('ادامه از فایل ناموفق');
                dialogError(errorText(error)+' فایل‌های موفق حفظ شده‌اند.');
            },
            onFinish:()=>{
                uploadBusy=false;pendingUploads=null;save.add(cancel).add(close).prop('disabled',false);
                modal.close();message('اطلاعات و پیوست‌ها ذخیره شدند.');Promise.resolve(finish()).catch(e=>message(errorText(e),true));
            }
        });
        form.append(progress,toolbar(save,cancel).addClass('ct-form-footer')).on('submit',async event=>{
            event.preventDefault();event.stopPropagation();if(formSaving || uploadBusy)return;
            dialogError('');
            try {
                if (!prepared) {const files=validateFiles(input);queue.prepare(files);prepared=true;pendingUploads=()=>queue.remaining();frozen=readForm(form);}
                formSaving=true;save.add(cancel).add(close).prop('disabled',true);
                if (!saved) saved=await saveRecord(frozen,createRequest);
                form.find('input,textarea,select').prop('disabled',true);
                formSaving=false;
                if (!queue.remaining()) {
                    pendingUploads=null;save.add(cancel).add(close).prop('disabled',false);modal.close();message('اطلاعات ذخیره شد.');await finish();return;
                }
                uploadBusy=true;save.text('در حال بارگذاری…');queue.start();
            } catch(error) {
                formSaving=false;save.add(cancel).add(close).prop('disabled',false);save.text('تلاش دوباره');
                // Validation errors did not create a record. Allow correction; uncertain outcomes stay frozen.
                const statusCode=error.status || 0;
                if (!saved && (statusCode===422 || statusCode===403 || statusCode===404 || !prepared)) {prepared=false;pendingUploads=null;frozen=null;}
                else if (!saved && prepared) form.find('input,textarea,select').prop('disabled',true);
                dialogError(errorText(error));
            }
        });
        return form;
    }
    function addDocuments(trigger) {
        const opening=caseId;
        openDialog('افزودن مدارک',trigger);
        const upload=fileInput(true);
        const form=fieldsForm([['role_code','select',opts('main draft amendment correspondence payment guarantee minutes attachment reference other'),true,'نوع مدارک']],{role_code:'other'});
        form.prepend(paragraph('هر فایل یک مدرک مستقل می‌شود. برای اسکن تازهٔ یک مدرک موجود، از «نسخهٔ جدید» استفاده کنید.').addClass('ct-field-note')).append(upload.host);
        box.append(queueEditor(form,upload.input,async values=>values,(values,file)=>({contract_id:opening,role_code:values.role_code}),()=>render()));
    }
    function reminderFields(row={}) {
        return fieldGrid([['reminder_title','text',[],true,'عنوان یادآوری'],['reminder_due','date',[],true,'موعد پیگیری'],
            ['reminder_owner','participant',[],true,'مسئول پیگیری']],{reminder_owner:caseData.contract.owner_uid,...row});
    }
    async function remindersView(target) {
        const result=await get('action_list',{...reminderFilters,offset:actionOffset});
        target.append(sectionHead('یادآوری‌ها',button('موعدهای قراردادها و تضمین‌ها',()=>navigate('dashboard'),'ct-text-link')),
            paragraph('موضوع‌های نیازمند پیگیری و نتیجهٔ کارهای انجام‌شده.').addClass('ct-field-note'));
        const chips=$('<div class="ct-category-bar">');
        [['open','باز'],['mine','مربوط به من'],['done','انجام‌شده'],['without_due','بدون موعد'],['cancelled','لغوشده']].forEach(([key,text])=>{
            const selected=reminderMode===key;
            chips.append(button(text,()=>{reminderMode=key;actionOffset=0;reminderFilters={state:key==='done'?'done':key==='cancelled'?'cancelled':'open',mine:key==='mine',without_due:key==='without_due'};return render();},'ct-chip').attr('aria-pressed',String(selected)));
        });
        target.append(chips);
        if (!result.items.length) target.append(emptyState('یادآوری‌ای در این بخش نیست.','از داخل پرونده، موضوع موردنظر را برای پیگیری ثبت کنید.'));
        for (const row of result.items) {
            const date=$('<div class="ct-reminder-date">').append(row.due_fa?bidi(UI.persianDigits(row.due_fa)):paragraph('بدون موعد'));
            const content=$('<div class="ct-reminder-body">').append($('<h3>').text(row.title),caseLink(row.contract_id,row.contract_title),
                paragraph('مسئول: '+owner(row.owner_uid)).addClass('ct-field-note'));
            const actions=toolbar();
            const inCase=async callback=>{caseId=row.contract_id;caseData=await get('get',{contract_id:caseId});return callback();};
            if(caps.can_update && ['open','in_progress'].includes(row.status)) actions.append(
                iconButton('ثبت نتیجه',t=>inCase(()=>completeAction(row,t)),'check','ct-soft'),
                button('ویرایش',t=>inCase(()=>actionEditor(row,t)),'ct-text-link'));
            if(caps.can_update && ['done','cancelled'].includes(row.status)) actions.append(button('بازگشایی',t=>inCase(()=>editor('بازگشایی یادآوری',
                [['reason','area',[],true],['happened_fa','date',[],true]],{happened_fa:faToday()},'action_reopen',entityParams(row),t)),'ct-text-link'));
            target.append($('<article class="ct-reminder ct-card">').append(date,content,badge(row.status),actions));
        }
        pager(target,actionOffset,result.more,offset=>{actionOffset=offset;return render();});
    }
    function navDraw() {
        nav.empty().append(iconButton('قراردادها',()=>navigate('list'),'archive').attr('aria-current',['list','case'].includes(active)?'page':null),
            iconButton('یادآوری‌ها',()=>navigate('actions'),'clock').attr('aria-current',active==='actions'?'page':null),
            menu('مدیریت آرشیو',[
                ['نمای کلی و موعدها',()=>navigate('dashboard')],
                ['ورود و بازبینی آرشیو',()=>navigate('import')],
                ['دسته‌های مشترک',()=>navigate('categories')]
            ]));
    }
    const mainSpecs = [['title','text',[],true],['official_number'],['contract_type'],['subject'],['description','area'],
        ['owner_uid','select',[]],['unit_name'],['project_name'],['site_name'],['signed_fa','date'],['starts_fa','date'],['ends_fa','date'],['notice_fa','date'],
        ['legal_status','select',opts('unknown draft awaiting_signature active suspended terminated closed')],
        ['signature_status','select',opts('unknown unsigned partial signed')],['amount_type','select',opts('unknown fixed unit_rate ceiling')],
        ['amount'],['currency'],['quantity_unit'],['tax_note'],['payment_terms','area'],['alert_days','number']];
    const archiveSpecs = [['original_location'],['archive_code'],['custodian'],['original_status','select',opts('unknown available missing digital_only')],
        ['archive_notes','area'],['retention_review_fa','date'],['hold_reason'],['reason']];
    const mainGroups = [
        ['اطلاعات اصلی',['title','official_number','contract_type','subject','owner_uid','description']],
        ['تاریخ‌ها و وضعیت',['signed_fa','starts_fa','ends_fa','notice_fa','legal_status','signature_status','alert_days']],
        ['مبلغ و شرایط پرداخت',['amount_type','amount','currency','quantity_unit','tax_note','payment_terms']],
        ['پروژه و واحد مسئول',['unit_name','project_name','site_name']]
    ];
    function disclosure(title,content,opened=false) {
        return $('<details class="ct-disclosure">').prop('open',opened)
            .append($('<summary>').text(title),$('<div class="ct-disclosure-content">').append(content));
    }
    function contractEditor(row,trigger) {
        openDialog(row ? 'ویرایش مشخصات' : 'ثبت قرارداد',trigger);
        const form=$('<form class="ct-contract-form">');
        const requestId=UI.requestId();
        if (row) {
            form.append(fieldGrid([mainSpecs[0],mainSpecs[1],mainSpecs[2],['signed_fa','date'],['ends_fa','date'],['original_location']],row));
            const extra=mainSpecs.filter(s=>!['title','official_number','contract_type','signed_fa','ends_fa'].includes(s[0])).map(s=>s[0]==='owner_uid'?['owner_uid','participant',[],true]:s);
            form.append(disclosure('اطلاعات تکمیلی',fieldGrid(extra,row)));
            form[0].addEventListener('invalid',event=>{const group=event.target.closest('details');if(group)group.open=true;},true);
        } else {
            form.append(paragraph('برای شروع، عنوان کافی است. مدارک و اطلاعات دیگر را بعداً هم می‌توانید اضافه کنید.').addClass('ct-field-note'),
                fieldGrid([mainSpecs[0],mainSpecs[1],mainSpecs[2]],{}));
            if(caps.can_update) form.append(field(['counterparty_name','text',[],false,'طرف قرارداد (اختیاری)'],''),
                paragraph('نام واردشده به‌عنوان طرف موقت پرونده ثبت می‌شود. انتخاب شخص یا شرکت از اطلاعات پایه، در مشخصات پرونده ممکن است.').addClass('ct-field-note'));
        }
        box.append(submit(form,async values=>{
            const response=await write(row?'update':'create',row?{...values,contract_id:row.id,lock_version:row.lock_version}:{...values,request_id:requestId});
            if(!row){active='case';tab='documents';caseId=response.data.id;historyCategory=null;}
            if(values.contract_type && !lookups.contract_types.includes(values.contract_type))lookups.contract_types.push(values.contract_type);
        }));
    }
    const listDefaults={archive:'all',sort:'id',direction:'desc'};
    const listPageSize=25;
    async function listView(target) {
        const query={...listDefaults,...filters};
        const result=await get('list',{...query,offset:listOffset,limit:listPageSize});
        const form=$('<form class="ct-filter-panel">');
        const search=field(['search','search',[],false,'جست‌وجوی قراردادها'],query.search);
        search.addClass('ct-searchbox').find('input').attr({placeholder:'عنوان، شماره، کد یا طرف قرارداد…',autocomplete:'off',maxlength:250});
        search.prepend(icon('search'));
        const type=field(['contract_type','select',lookups.contract_types.map(t=>[t,t]),false,'نوع قرارداد'],query.contract_type);
        type.find('option').first().text('همهٔ انواع قرارداد');
        const advancedSpecs=[['archive','select',opts('all current archived merged'+(caps.can_delete?' deleted':'')),true,'دامنهٔ بایگانی'],
            ['review_status','select',opts('provisional needs_review verified'),false,'بازبینی اطلاعات'],['signed_year','text',[],false,'سال قرارداد'],
            ['without_files','checkbox',[],false,'پرونده‌های بدون فایل'],['legal_status','select',opts('unknown draft awaiting_signature active suspended terminated closed')],
            ['owner_uid','select',users()],['sort','select',[['id','جدیدترین پرونده'],['title','عنوان'],['official_number','شماره'],['ends_on','پایان'],['updated_at','آخرین تغییر']],true,'مرتب‌سازی'],
            ['direction','select',[['desc','نزولی'],['asc','صعودی']],true,'جهت مرتب‌سازی']];
        const advanced=$('<div class="ct-advanced-filters">').prop('hidden',!advancedFiltersOpen).append(fieldGrid(advancedSpecs,query));
        const toggle=button('فیلتر بیشتر',()=>{advancedFiltersOpen=!advancedFiltersOpen;advanced.prop('hidden',!advancedFiltersOpen);toggle.attr('aria-expanded',String(advancedFiltersOpen));}).attr({'aria-expanded':String(advancedFiltersOpen),'aria-controls':'ct-advanced-filters'});
        advanced.attr('id','ct-advanced-filters');
        const clear=button('پاک کردن فیلترها',()=>openContractList(),'ct-text-link');
        const results=$('<div class="ct-list-results">');
        const paint=data=>{
            clear.prop('hidden',!Object.keys(query).some(key=>query[key] && query[key]!==listDefaults[key]));
            const total=Number(data.total),recover=query.archive==='deleted'&&caps.can_update&&caps.can_delete;
            results.empty().append($('<p class="ct-results" tabindex="-1" role="status">').text(total?UI.persianDigits(listOffset+1)+' تا '+UI.persianDigits(listOffset+data.items.length)+' از '+UI.persianDigits(total)+' پرونده':'۰ پرونده'));
            if(!data.items.length){results.append(emptyState('پرونده‌ای پیدا نشد.','عبارت جست‌وجو یا فیلترها را تغییر دهید.'));return;}
            const list=$('<div class="ct-archive-list">').append($('<div class="ct-list-head" aria-hidden="true">').append(['پرونده','طرف قرارداد','مدارک','تاریخ قرارداد',''].map(t=>$('<span>').text(t))));
            for(const row of data.items){
                const name=$('<div class="ct-file-identity">').append($('<div class="ct-folder">').append(icon('folder')),
                    $('<div>').append(row.deleted_at?$('<strong>').text(row.title):caseLink(row.id,row.title),
                        $('<div class="ct-record-sub">').append(bidi(row.official_number || row.code,'auto'),
                            row.review_status==='provisional'?badge('provisional','اطلاعات بررسی‌نشده'):row.review_status==='needs_review'?badge('needs_review'):null,
                            row.archived_at?badge('archived'):null)));
                list.append($('<article class="ct-contract-row">').append(name,
                    $('<div class="ct-party-cell">').text(row.counterparty || 'طرف ثبت نشده'),
                    $('<div class="ct-docs-cell">').append(Number(row.file_count)?UI.persianDigits(row.document_count)+' مدرک':badge('provisional','بدون فایل')),
                    $('<div class="ct-list-date">').append(bidi(row.signed_fa?UI.persianDigits(row.signed_fa):'ثبت نشده')),
                    $('<div class="ct-open-cell">').append(recover?button('بازیابی',async()=>{await write('restore',{contract_id:row.id,lock_version:row.lock_version});await render();}):
                        row.deleted_at?null:button('مشاهده',()=>navigate('case',row.id),'ct-soft'))));
            }
            results.append(list);
            pager(results,listOffset,listOffset+listPageSize<total,offset=>{listOffset=offset;lastListFocus='results';return render();});
        };
        let requestEpoch=0,timer;
        const searchNow=async()=>{
            if(!document.contains(form[0]))return;
            const currentRequest=++requestEpoch;
            filters=readForm(form);filters.search=filters.search.trim();Object.assign(query,filters);listOffset=0;
            try {const data=await get('list',{...query,offset:0,limit:listPageSize});if(currentRequest===requestEpoch&&document.contains(form[0]))paint(data);}
            catch(error){if(currentRequest===requestEpoch)message(errorText(error),true);}
        };
        form.append($('<div class="ct-search-line">').append(search,type,toggle),advanced,clear);
        form.on('submit',event=>{event.preventDefault();event.stopPropagation();clearTimeout(timer);searchNow();});
        form.find('select,input[type=checkbox]').on('change',searchNow);
        form.find('input:not([type=checkbox])').on('input',()=>{clearTimeout(timer);timer=setTimeout(searchNow,350);});
        target.append(sectionHead('قراردادها',toolbar(caps.can_create&&iconButton('ثبت قرارداد',t=>contractEditor(null,t),'plus','ec-primary'),
            button('خروجی اکسل',()=>transfer('export_list',{...listDefaults,...filters}),'ct-text-link'))),
            paragraph('پرونده را پیدا کنید؛ مدارک و سوابقش همین‌جاست.').addClass('ct-list-lead'),form,results);
        paint(result);
    }
    function metric(data,key,text,options={}) {
        const count=data[key];
        const display=count==null ? '—' : UI.persianDigits(count);
        const term=$('<dt>').text(text);
        const amount=$('<dd>').text(display);
        const tile=$('<div class="ct-stat">').attr('data-tone',options.tone || 'neutral').append(term,amount);
        if (options.action && Number(count)>0) {
            const shortcut=button(text,options.action,'ct-stat-link').attr('aria-label',text+'، '+display);
            term.empty().append(shortcut);
        }
        return tile;
    }
    function dashboardPanel(title,children) {
        return $('<section class="ct-surface">').append(heading(title),...children);
    }
    function extractedDates(raw) {
        let dates=raw;
        if (typeof raw==='string') {
            try { dates=JSON.parse(raw); } catch (_) { dates=[]; }
        }
        if (!Array.isArray(dates) || !dates.length) return bidi(null);
        return $('<div class="ct-date-values">').append(dates.map(date=>bidi(UI.persianDigits(String(date)))));
    }
    async function dashboard(target) {
        const data=await get('dashboard');
        const counts=data.quality, candidates=data.candidate_dates;
        const reviewList=state=>openContractList({archive:'all',review_status:state});
        const first=$('<dl class="ct-summary-grid">').append(
            metric(counts,'total','همهٔ پرونده‌ها',{action:()=>openContractList({archive:'all'})}),
            metric(counts,'provisional','پرونده‌های موقت',{tone:'attention',action:()=>reviewList('provisional')}),
            metric(counts,'needs_review','نیازمند بازبینی',{tone:Number(counts.needs_review)>0?'attention':'neutral',action:()=>reviewList('needs_review')}),
            metric(counts,'verified','تأییدشده',{tone:'positive',action:()=>reviewList('verified')}));
        const followup=$('<dl class="ct-inline-stats">').append(
            metric(counts,'overdue','گذشته',{tone:Number(counts.overdue)>0?'danger':'neutral'}),
            metric(counts,'today','امروز',{tone:Number(counts.today)>0?'attention':'neutral'}),
            metric(counts,'upcoming','نزدیک'),
            metric(counts,'open_actions','اقدام باز',{action:()=>navigate('actions')}));
        const deadlines=table(['پرونده','عنوان','نوع','موعد','وضعیت'],data.deadlines.map(r=>{
            const days=Number(r.days_left);
            const key=days<0?'overdue':days===0?'today':'upcoming';
            const remaining=days<0 ? UI.persianDigits(Math.abs(days))+' روز گذشته'
                : days===0 ? 'امروز' : UI.persianDigits(days)+' روز باقی‌مانده';
            return [caseLink(r.contract_id),r.title,value(r.kind,true),bidi(r.due_fa ? UI.persianDigits(r.due_fa) : null),badge(key,remaining)];
        }),{name:'موعدهای فعال',emptyTitle:'موعد فعالی برای نمایش نیست.',emptyDescription:'موعدهای ثبت‌شدهٔ قراردادها، اقدامات و تعهدات در این بخش نمایش داده می‌شوند.'});
        const qualityKeys=[
            ['missing_basis','بدون مدرک مبنا'],['missing_original','بدون محل اصل'],['unverified_dates','تاریخ پایان نیازمند بررسی'],
            ['unverified_amounts','مبلغ نیازمند بررسی'],['missing_parties','طرفین ناقص'],['files','نسخه‌های فایل'],
            ['unknown_role','مدرک با نقش نامشخص'],['without_due','اقدام بدون موعد'],['without_active_owner','اقدام بدون مسئول فعال']
        ];
        const quality=$('<dl class="ct-stat-list">').append(qualityKeys.map(([key,text])=>metric(counts,key,text)));
        const extracted=candidates.length ? disclosure('تاریخ‌های استخراج‌شده از اسناد',
            [paragraph('این تاریخ‌ها از متن اسناد استخراج شده‌اند و برای استفاده به بررسی نیاز دارند.'),
             table(['پرونده','مدرک مبدأ','تاریخ‌های دیده‌شده'],candidates.map(r=>[
                 caseLink(r.contract_id),bidi(r.doc_id),extractedDates(r.candidates)
             ]),{name:'تاریخ‌های استخراج‌شده'}),
             candidates.length===100 ? paragraph('حداکثر ۱۰۰ مدرک در این بخش نمایش داده می‌شود.').addClass('ct-field-note') : null],
            candidatesOpen) : null;
        if (extracted) extracted.on('toggle',()=>{ candidatesOpen=extracted.prop('open'); });
        const provisionalNote=Number(counts.provisional)>0
            ? paragraph('برای بررسی مدارک و تأیید پرونده‌های موقت، از فهرست آن‌ها شروع کنید.').addClass('ct-field-note')
            : null;
        target.append(heading('نمای کلی'),first,provisionalNote,dashboardPanel('موعدها و پیگیری',[followup,deadlines]),
            disclosure('کیفیت اطلاعات پرونده‌ها',quality),extracted,
            data.sources.length ? disclosure('وضعیت اسناد مبدأ',table(['وضعیت','تعداد'],data.sources.map(r=>[
                badge(r.status),UI.persianDigits(r.total)
            ]),{name:'وضعیت اسناد مبدأ'})) : null);
    }

    async function categoriesView(target,contract) {
        const rows = await get('category_list',contract ? {contract_id:contract} : {});
        const specs = [['title','text',[],true],['sort_order','number'],['is_active','checkbox']];
        target.append(heading(contract?'دسته‌های اقدامات':'دسته‌های مشترک'),
            caps.can_update && button('افزودن دسته',t=>editor('افزودن دسته',specs,{sort_order:0,is_active:true},'category_create',{contract_id:contract},t)),
            table(['عنوان','دامنه','ترتیب','وضعیت','عملیات'],rows.map(r=>[
                r.title,r.contract_id?'این پرونده':'مشترک',r.sort_order,Number(r.is_active)?'فعال':'غیرفعال',
                caps.can_update && (contract ? !!r.contract_id : !r.contract_id) ? button('ویرایش',t=>editor('ویرایش دسته',specs,r,'category_update',
                    {id:r.id,contract_id:contract,lock_version:r.lock_version},t)) : '—'
            ])));
    }
    function linkedOptions(key) {
        if (key==='amendments') return caseData.amendments.map(r=>[r.id,r.number || r.description]);
        return names(caseData[key]);
    }
    function categoryOptions(row) {
        return caseData.categories.filter(c=>Number(c.is_active) || String(c.id)===String(row && row.category_id)).map(c=>[
            c.id,c.title + (c.contract_id ? ' (اختصاصی)' : '')
        ]);
    }
    async function getChoices(action,payload) {
        const output = [];
        for (let offset=0;;offset+=100) {
            const page=await get(action,{...payload,offset,limit:100});
            output.push(...page.items);
            if (!page.more) return output;
        }
    }
    async function caseVersions() {
        const documents=await getChoices('document_list',{contract_id:caseId});
        const versions=[];
        for (const doc of documents) {
            for (const version of await getChoices('version_list',{contract_id:caseId,document_id:doc.id})) {
                versions.push([version.id,doc.title+' / نسخهٔ '+UI.persianDigits(version.version_no)]);
            }
        }
        return versions;
    }
    function caseParams() { return {contract_id:caseId,lock_version:caseData.contract.lock_version}; }
    function entityParams(row) { return {contract_id:caseId,id:row.id,lock_version:row.lock_version}; }
    async function verifyDialog(trigger) {
        const versions=await caseVersions();
        const row=caseData.contract;
        openDialog('تأیید اطلاعات پرونده',trigger);
        const checkKeys=['official_number','signed_fa','starts_fa','ends_fa','notice_fa','amount','signature_status','parties'];
        const form=fieldsForm([['basis_version_id','select',versions,true]],row);
        for (const key of checkKeys) {
            const shown = key==='parties' ? caseData.parties.map(p=>p.name).join('، ') : value(row[key],true);
            const current = key==='parties' || (row[key] != null && row[key]!=='unknown') ? 'confirmed' : 'not_stated';
            form.append($('<fieldset class="ct-card">').append($('<legend>').text((label[key] || 'طرفین') + ' | ' + shown),
                $('<div class="ct-fields">').append(
                    field([key+'_outcome','select',opts('confirmed not_stated pending'),true,'نتیجهٔ بررسی'],current),
                    field([key+'_version','select',versions,false,'نسخهٔ شاهد (اختیاری)']),
                    field([key+'_locator','text',[],true,'صفحه یا بند']),
                    field([key+'_raw','area',[],false,'مقدار خام یا توضیح بررسی']))));
        }
        box.append(submit(form,fields=>{
            const checks={};
            checkKeys.forEach(key=>{
                checks[key]={outcome:fields[key+'_outcome'],locator:fields[key+'_locator'],raw_value:fields[key+'_raw']};
                if (fields[key+'_version']) checks[key].version_id=fields[key+'_version'];
            });
            return write('verify',{...caseParams(),basis_version_id:fields.basis_version_id,checks});
        },'تأیید پرونده'));
    }
    function partyEditor(row,trigger) {
        const specs=[['side','select',opts('ours other'),true],['kind','select',opts('company person manual'),true],
            ['reference_id','select',[]],['name'],['role_name'],['national_id'],['registration_number'],['address']];
        openDialog(row?'ویرایش طرف':'افزودن طرف قرارداد',trigger);
        const form=fieldsForm(specs,row || {kind:'manual',side:'other'});
        const kind=form.find('[name=kind]'), ref=form.find('[name=reference_id]');
        function fill(selected) {
            ref.empty().append($('<option>').val('').text('انتخاب کنید'));
            const options=kind.val()==='company'?lookups.companies:kind.val()==='person'?lookups.persons:[];
            names(options).forEach(([id,name])=>ref.append($('<option>').val(id).text(name)));
            if (selected && !options.some(r=>String(r.id)===String(selected))) ref.append($('<option>').val(selected).text(row.name));
            ref.prop({disabled:kind.val()==='manual',required:kind.val()!=='manual'}).val(selected || '');
            form.find('[name=name]').prop('required',kind.val()==='manual');
        }
        kind.on('change',()=>fill(null));
        fill(row && row.reference_id);
        box.append(submit(form,fields=>write(row?'party_update':'party_create',{...caseParams(),id:row && row.id,...fields})));
    }
    function partiesView(target) {
        target.append(heading('طرفین و ارتباطات'),caps.can_update && button('افزودن طرف',t=>partyEditor(null,t)),
            table(['نام','سمت','نقش','شناسهٔ ملی','شمارهٔ ثبت','نشانی','ویرایش'],caseData.parties.map(p=>[
                p.name,value(p.side,true),p.role_name,p.national_id,p.registration_number,p.address,
                caps.can_update ? button('ویرایش',t=>partyEditor(p,t)):'—'
            ])),heading('معادن مرتبط'),caps.can_update && button('اتصال معدن',t=>editor('اتصال معدن',
                [['mine_id','select',names(lookups.mines),true,'معدن']],{},'mine_set',{...caseParams(),enabled:true},t)),
            table(['معدن','وضعیت مرجع','عملیات'],caseData.mines.map(m=>[
                m.name || String(m.mine_id),m.deleted_at?'غیرفعال در سامانه':'جاری',
                caps.can_update ? button('برداشتن ارتباط',async()=>{await write('mine_set',{...caseParams(),mine_id:m.mine_id,enabled:false}); await render();}):'—'
            ])));
    }
    async function versionDialog(doc,trigger,offset=0,deleted=false) {
        const openingEpoch=dialogEpoch;
        const result=await get('version_list',{contract_id:caseId,document_id:doc.id,offset,include_deleted:deleted});
        if(openingEpoch!==dialogEpoch)return;
        const currentCase=caseId;
        openDialog('نسخه‌ها | '+doc.title,trigger);
        const reload=()=>versionDialog(result.document,trigger,offset,deleted);
        const params={contract_id:caseId,document_id:doc.id,lock_version:result.document.lock_version};
        box.append(toolbar(caps.can_update && button('افزودن نسخهٔ جدید',t=>uploadDialog(result.document,t)),
            caps.can_delete && button(deleted?'فقط نسخه‌های موجود':'نمایش نسخه‌های حذف‌شده',()=>versionDialog(doc,trigger,0,!deleted))),
            table(['نسخه','نام و توضیح فایل','وضعیت','عملیات'],result.items.map(v=>{
                const link={contract_id:currentCase,document_id:doc.id,version_id:v.id};
                const edit={...params,version_id:v.id};
                const current=String(v.id)===String(result.document.current_version_id);
                return [UI.persianDigits(v.version_no),paragraph(v.original_filename + (v.change_note ? ' | '+v.change_note : '')),
                    v.deleted_at?'حذف‌شده':current?'جاری':'—',
                    v.deleted_at ? toolbar(caps.can_update && caps.can_delete && button('بازیابی',async()=>{await write('restore_version',edit); await reload();})) :
                        toolbar(button('دریافت',()=>transfer('download',link)),
                            /^(application\/pdf|image\/)/.test(v.mime_type) && button('پیش‌نمایش',t=>showFile(doc,{id:v.id,mime_type:v.mime_type},t)),
                            Number(v.has_text) && button('متن استخراج‌شده',async t=>{
                                const text=await get('extracted_text',link);
                                openDialog('متن استخراج‌شده',t).append($('<pre dir="auto">').text(text.text || ''),
                                    button('بازگشت به نسخه‌ها',reload));
                            }),
                            caps.can_update && !current && button('تعیین به‌عنوان نسخهٔ جاری',async()=>{
                                const epoch=dialogEpoch;
                                await write('set_current_version',edit);
                                await render();
                                if(epoch===dialogEpoch && modal.isOpen())await reload();
                            }),
                            caps.can_delete && !current && button('حذف',t=>askReason('حذف نرم نسخه','delete_version',edit,t)))]
            })));
        pager(box,offset,result.more,position=>versionDialog(doc,trigger,position,deleted));
    }
    function uploadDialog(doc,trigger) {
        openDialog('نسخهٔ جدید: '+doc.title,trigger);
        const upload=fileInput(true,false),requestId=UI.requestId();
        const form=fieldsForm([['change_note','area'],['make_current','checkbox',[],false,'این نسخه در نمای اصلی مدرک نمایش داده شود']],{make_current:false});
        form.prepend(paragraph('فقط نسخهٔ دیگری از همین مدرک را انتخاب کنید. فایل قبلی حفظ می‌شود.').addClass('ct-field-note'),upload.host);
        let payload=null,file=null;
        box.append(submit(form,async fields=>{
            if(!payload){const files=validateFiles(upload.input);if(!files.length)throw new Error('یک فایل انتخاب کنید.');file=files[0];payload={...fields,contract_id:caseId,document_id:doc.id,lock_version:doc.lock_version,request_id:requestId};}
            try {await api('upload',payload,file);}catch(error){if(error.status===422){payload=null;file=null;}else form.find('input,textarea').prop('disabled',true);throw error;}
        },'افزودن نسخه'));
    }
    async function documentsView(target,offset=0) {
        const result=await get('document_list',{contract_id:caseId,offset});
        const specs=[['title','text',[],true],['role_code','select',opts('main draft amendment attachment payment guarantee correspondence identity minutes reference other'),true],['signature_status','select',opts('unknown unsigned partial signed'),true]];
        target.append(sectionHead('مدارک پرونده',toolbar(caps.can_update&&iconButton('افزودن مدارک',addDocuments,'plus','ec-primary').prop('disabled',!storageReady),
            caps.can_update&&menu('بیشتر',[
                ['ثبت مشخصات مدرک بدون فایل',t=>editor('ثبت مدرک بدون فایل',specs,{role_code:'main',signature_status:'unknown'},'document_create',{contract_id:caseId},t)],
                ['الحاقیه و تغییر مبلغ یا مدت',()=>{tab='amendments';return render();}]
            ]))));
        if(!storageReady)target.append(paragraph('مخزن فایل در دسترس نیست؛ مشاهدهٔ مشخصات و ثبت بدون فایل همچنان ممکن است.').addClass('ct-note'));
        const grid=$('<div class="ct-document-grid">');
        for(const doc of result.items){
            const current=doc.display_version_id?{id:doc.display_version_id,mime_type:doc.mime_type}:null;
            const view=trigger=>current?showFile(doc,current,trigger):versionDialog(doc,trigger);
            const art=button('',view,'ct-doc-preview').attr('aria-label','پیش‌نمایش '+doc.title).append(
                $('<span class="ct-paper" aria-hidden="true">').append($('<b>').text(value(doc.role_code,true)),$('<i>'),$('<i>'),$('<i>'),$('<i>')),
                $('<span class="ct-preview-label">').append(icon('eye'),document.createTextNode('مشاهدهٔ مدرک')));
            const card=$('<article class="ct-document">').append($('<div class="ct-doc-heading">').append($('<div class="ct-doc-icon">').append(icon('file')),
                $('<div>').append($('<h3>').text(doc.title),paragraph(value(doc.role_code,true)).addClass('ct-field-note'))));
            card.append(art,paragraph(current?doc.original_filename:Number(doc.version_count)?'نسخهٔ قابل نمایش انتخاب نشده':'اسکن دریافت نشده').attr('dir','auto').addClass('ct-filename'),
                toolbar(button(current?'مشاهده':'نسخه‌ها',view),current&&iconButton('دریافت',()=>transfer('download',{contract_id:caseId,document_id:doc.id,version_id:current.id}),'download')).addClass('ct-doc-primary'),
                $('<div class="ct-doc-footer">').append(button('نسخه‌ها ('+UI.persianDigits(doc.version_count)+')',t=>versionDialog(doc,t),'ct-text-link'),
                    caps.can_update&&button('نسخهٔ جدید',t=>uploadDialog(doc,t),'ct-text-link').prop('disabled',!storageReady),
                    caps.can_update&&button('ویرایش',t=>editor('ویرایش مدرک',specs,doc,'document_update',entityParams(doc),t),'ct-text-link')));
            grid.append(card);
        }
        target.append(result.items.length?grid:emptyState('هنوز مدرکی اضافه نشده است.','فایل قرارداد و مدارک مرتبط را با «افزودن مدارک» ثبت کنید.'));
        pager(target,offset,result.more,position=>render(position));
        target.append(paragraph('هر فایل در «افزودن مدارک» یک مدرک مستقل است؛ «نسخهٔ جدید» برای اسکن تازهٔ همان مدرک است.').addClass('ct-library-note'));
    }
    async function actionEditor(row,trigger) {
        const documents=await getChoices('document_list',{contract_id:caseId});
        openDialog(row?'ویرایش یادآوری':'افزودن یادآوری',trigger);
        const form=fieldsForm([['title','text',[],true,'عنوان یادآوری'],['category_id','select',categoryOptions(row),true],
            ['due_fa','date'],['owner_uid','participant',[],true]],row||{owner_uid:caseData.contract.owner_uid});
        form.append(disclosure('جزئیات بیشتر',fieldGrid([['description','area'],['priority','select',opts('low normal high'),true],
            ['status','select',opts('open in_progress cancelled'),true],['document_id','select',names(documents)],
            ['amendment_id','select',linkedOptions('amendments')],['obligation_id','select',linkedOptions('obligations')]],row||{priority:'normal',status:'open'})));
        const requestId=UI.requestId();
        box.append(submit(form,fields=>write(row?'action_update':'action_create',{...(row?entityParams(row):{contract_id:caseId}),...fields,request_id:requestId})));
    }
    async function editHistory(row,trigger) {
        const opening=caseId;
        openDialog(row?'اصلاح سابقه':'ثبت سابقه',trigger);
        const choices=categoryOptions(row);
        if(!row&&caps.can_update)choices.push(['new','دستهٔ تازه برای این پرونده…']);
        const form=fieldsForm([['category_id','select',choices,true],['happened_fa','date',[],true],['description','area',[],true,'شرح سابقه']],row||{happened_fa:faToday(),category_id:historyCategory});
        const newCategory=field(['new_category','text',[],false,'عنوان دستهٔ تازه'],'').prop('hidden',true);
        form.find('.ct-fields').append(newCategory);
        form.find('[name=category_id]').on('change',function(){newCategory.prop('hidden',this.value!=='new');newCategory.find('input').prop('required',this.value==='new');});
        if(row)form.append(field(['reason','area',[],true],''));
        const upload=fileInput();
        if(caps.can_update&&storageReady)form.append(upload.host);
        else form.append(paragraph(caps.can_update?'مخزن در دسترس نیست؛ سابقه بدون پیوست ثبت می‌شود.':'افزودن پیوست به مجوز ویرایش قرارداد نیاز دارد.').addClass('ct-field-note'));
        if(!row){
            const toggle=field(['has_reminder','checkbox',[],false,'برای این موضوع یادآوری بگذار'],false).addClass('ct-reminder-toggle');
            const reminders=$('<div class="ct-reminder-fields" hidden>').append(reminderFields());
            reminders.find('input,select,textarea').prop('disabled',true);
            toggle.find('input').on('change',function(){reminders.prop('hidden',!this.checked);reminders.find('input,select,textarea').prop('disabled',!this.checked);});
            form.append(toggle,reminders);
        }
        const save=async(fields,requestId)=>{
            const input={contract_id:opening,category_id:fields.category_id,description:fields.description,happened_fa:fields.happened_fa,request_id:requestId};
            if(fields.category_id==='new'){delete input.category_id;input.new_category=fields.new_category;}
            if(row)Object.assign(input,{id:row.id,lock_version:row.lock_version,reason:fields.reason});
            if(fields.has_reminder)input.reminder={title:fields.reminder_title,due_fa:fields.reminder_due,owner_uid:fields.reminder_owner};
            const response=await write(row?'history_update':'history_create',input);return response.data;
        };
        box.append(queueEditor(form,upload.input,save,(saved,file)=>({contract_id:opening,history_id:saved.id,history_version:saved.lock_version,role_code:'attachment'}),()=>{historyCategory=null;return render();}));
    }
    async function completeAction(row,trigger) {
        const documents=await getChoices('document_list',{contract_id:caseId});
        editor('ثبت نتیجهٔ پیگیری',[['completed_fa','date',[],true],['result','area',[],true],['document_id','select',names(documents)]],
            {completed_fa:faToday(),document_id:row.document_id},'action_complete',entityParams(row),trigger);
    }
    async function actionsView(target, options, offset) {
        const result=await get('action_list',{...options,offset});
        const inCase=!!options.contract_id;
        target.append(heading(inCase?'یادآوری‌های پرونده':options.mine?'یادآوری‌های من':'همهٔ یادآوری‌ها'),
            inCase && caps.can_create && button('افزودن یادآوری',t=>actionEditor(null,t)),
            table(['عنوان','دسته','مسئول','موعد','وضعیت','عملیات'],result.items.map(r=>[
                inCase?r.title:caseLink(r.contract_id,r.title),r.category_title,owner(r.owner_uid),r.due_fa,value(r.status,true),
                inCase && caps.can_update ? toolbar(
                    ['open','in_progress'].includes(r.status) && button('ویرایش',t=>actionEditor(r,t)),
                    ['open','in_progress'].includes(r.status) && button('ثبت نتیجه',t=>completeAction(r,t)),
                    ['done','cancelled'].includes(r.status) && button('بازگشایی',t=>editor('بازگشایی اقدام',
                        [['reason','area',[],true],['happened_fa','date',[],true]],{happened_fa:faToday()},'action_reopen',entityParams(r),t))
                ) : '—'
            ])));
        pager(target,offset,result.more,position=>{if (!inCase) actionOffset=position; return render(inCase?position:0);});
    }
    async function historyView(target,offset=0) {
        const result=await get('history_list',{contract_id:caseId,offset,category_id:historyCategory});
        target.append(sectionHead('سوابق پرونده',toolbar(caps.can_create&&iconButton('ثبت سابقه',t=>editHistory(null,t),'plus','ec-primary'),
            caps.can_create&&button('افزودن یادآوری',t=>actionEditor(null,t),'ct-text-link'))));
        const chips=$('<div class="ct-category-bar">').append(button('همه',()=>{historyCategory=null;return render();},'ct-chip').attr('aria-pressed',String(!historyCategory)));
        caseData.categories.filter(c=>Number(c.record_count)>0).forEach(c=>chips.append(button(c.title+' · '+UI.persianDigits(c.record_count),()=>{historyCategory=c.id;return render();},'ct-chip')
            .attr('aria-pressed',String(String(historyCategory)===String(c.id)))));
        target.append(chips);
        if(!result.items.length)target.append(emptyState('هنوز سابقه‌ای در این دسته ثبت نشده است.','شرح مکاتبه، تمدید یا تحویل را همراه مدارکش ثبت کنید.'));
        for(const row of result.items){
            const card=$('<article class="ct-history-entry">');
            const meta=$('<div class="ct-history-meta">').append(bidi(UI.persianDigits(row.happened_fa)),badge('verified',row.category_title),
                $('<span>').text('ثبت‌کننده: '+(row.author_name?.trim()||owner(row.created_by))));
            const files=$('<div class="ct-history-files">');
            for(const file of row.attachments){
                const item=$('<div class="ct-history-file">').append(iconButton(file.original_filename,t=>showFile({id:file.document_id,title:file.document_title},
                    {id:file.version_id,mime_type:file.mime_type},t),'clip','ct-attachment'));
                if(caps.can_update&&caps.can_delete)item.append(button('برداشتن پیوند',t=>editor('برداشتن پیوست از سابقه',[['reason','area',[],true]],{},'history_file_delete',
                    {contract_id:caseId,history_id:row.id,id:file.id,lock_version:row.lock_version},t),'ct-text-link ct-small'));
                files.append(item);
            }
            if(row.document_id) files.append(button(row.legacy_document_title||'مدرک مرتبط',async t=>{
                const res=await get('version_list',{contract_id:caseId,document_id:row.document_id});return versionDialog(res.document,t);
            },'ct-attachment'),paragraph('پیوند قدیمی به مدرک است؛ نسخهٔ زمان ثبت سابقه مشخص نشده.').addClass('ct-field-note'));
            const actions=toolbar(caps.can_update&&button('ویرایش و افزودن پیوست',t=>editHistory(row,t),'ct-text-link'),
                caps.can_delete&&button('حذف',t=>askReason('حذف سابقه','history_delete',entityParams(row),t),'ct-text-link'));
            card.append(meta,paragraph(row.description).addClass('ct-history-description'),files,actions);target.append(card);
        }
        pager(target,offset,result.more,position=>render(position));
    }
    async function obligationEditor(row,trigger) {
        const documents=await getChoices('document_list',{contract_id:caseId});
        const specs=[['kind','select',opts('obligation guarantee insurance'),true],['title','text',[],true],['party_id','select',names(caseData.parties)],
            ['owner_uid','participant'],['issuer'],['number'],['amount'],['currency'],['due_fa','date'],
            ['status','select',opts('open fulfilled released cancelled'),true],['document_id','select',names(documents)],['notes','area']];
        editor(row?'ویرایش قلم':'ثبت تعهد، تضمین یا بیمه',specs,row || {kind:'obligation',status:'open'},row?'obligation_update':'obligation_create',
            row?entityParams(row):{contract_id:caseId},trigger);
    }
    function obligationsView(target) {
        target.append(heading('تعهدات، بیمه و تضمین‌ها'),caps.can_update && button('افزودن قلم',t=>obligationEditor(null,t)),
            table(['عنوان','نوع','موعد','مبلغ','وضعیت','عملیات'],caseData.obligations.map(r=>[
                r.title,value(r.kind,true),r.due_fa,r.amount===null?'—':r.amount+' '+value(r.currency,true),value(r.status,true),
                toolbar(caps.can_update && button('ویرایش',t=>obligationEditor(r,t)),
                    caps.can_delete && button('حذف',t=>askReason('حذف نرم قلم','obligation_delete',entityParams(r),t)))
            ])));
    }

    async function amendmentEditor(row,trigger) {
        const versions=await caseVersions();
        const specs=[['number'],['signed_fa','date'],['effective_fa','date',[],true],['description','area',[],true],
            ['basis_version_id','select',versions,true],['corrects_id','select',caseData.amendments.filter(r=>r.applied_at).map(r=>[r.id,r.number || r.description])]];
        openDialog(row?'ویرایش الحاقیه':'ثبت الحاقیه',trigger);
        const form=fieldsForm(specs,row || {});
        const proposal=row ? JSON.parse(row.proposed) : {};
        ['starts_fa','ends_fa','notice_fa','amount','currency','amount_type'].forEach(key=>{
            const spec=mainSpecs.find(s=>s[0]===key);
            form.append($('<div class="ct-card">').append(
                field(['apply_'+key,'checkbox',[],false,'تغییر '+label[key]],key in proposal),field(spec,proposal[key])));
        });
        box.append(submit(form,fields=>{
            const proposed={};
            Object.keys(fields).filter(k=>k.startsWith('apply_')).forEach(k=>{
                const key=k.slice(6);
                if (fields[k]) proposed[key]=fields[key] || null;
                delete fields[k]; delete fields[key];
            });
            return write(row?'amendment_update':'amendment_create',{...(row?entityParams(row):{contract_id:caseId}),...fields,proposed});
        }));
    }
    function applyAmendment(row,trigger) {
        openDialog('اعمال الحاقیه',trigger);
        const proposed=JSON.parse(row.proposed);
        box.append(table(['فیلد','مقدار فعلی','مقدار جدید'],Object.keys(proposed).map(key=>[label[key],value(caseData.contract[key],true),value(proposed[key],true)])));
        const form=fieldsForm([['confirm','checkbox',[],true,'اعمال مقادیر بالا را تأیید می‌کنم']],{});
        box.append(submit(form,fields=>write('amendment_apply',{...entityParams(row),confirmed:fields.confirm,
            contract_version:caseData.contract.lock_version}),'اعمال الحاقیه'));
    }
    function amendmentsView(target) {
        target.append(heading('الحاقیه‌ها'),caps.can_update && button('ثبت الحاقیه',t=>amendmentEditor(null,t)),
            table(['شماره','شرح','تاریخ اثر','وضعیت','عملیات'],caseData.amendments.map(r=>[
                r.number,r.description,r.effective_fa,r.applied_at?'اعمال‌شده':'در انتظار',
                toolbar(caps.can_update && !r.applied_at && button('ویرایش',t=>amendmentEditor(r,t)),
                    caps.can_update && !r.applied_at && r.effective_on<=today && button('اعمال',t=>applyAmendment(r,t)),
                    r.applied_at && button('مشاهدهٔ مقادیر پیشین',t=>{
                        const previous=JSON.parse(r.previous_values);
                        openDialog('مقادیر پیش از اعمال',t).append(table(['فیلد','مقدار پیشین'],Object.keys(previous).filter(k=>label[k])
                            .map(k=>[label[k],value(previous[k],true)])));
                    }))
            ])));
    }
    async function structureDialog(operation,trigger) {
        const documents=operation==='merge' ? [] : await getChoices('document_list',{contract_id:caseId});
        openDialog(operation==='merge'?'ادغام پرونده':operation==='split'?'تفکیک پرونده':'انتقال مدرک',trigger);
        const form = fieldsForm(operation==='split' ? [['title','text',[],true,'عنوان پروندهٔ جدید']] : [['target_id','text',[],true,'کد داخلی یا شناسهٔ مقصد']],{});
        const choices=$('<fieldset class="ct-checkboxes">').append($('<legend>').text('مدارک انتخابی'));
        documents.forEach(doc=>choices.append($('<label>').append($('<input type="checkbox">').val(doc.id),document.createTextNode(' '+doc.title))));
        if (operation!=='merge') form.append(choices);
        box.append(submit(form,async fields=>{
            const payload={...fields,contract_id:caseId,operation};
            if (payload.target_id) payload.target_id=UI.latinDigits(payload.target_id).replace(/^CTR-0*/i,'');
            if (operation!=='merge') payload.document_ids=choices.find(':checked').map(function () {return this.value;}).get();
            const preview=await get('structure_preview',payload);
            openDialog('پیش‌نمایش تغییر ساختار',trigger);
            box.append(paragraph('مبدأ: '+preview.source.code+' | '+preview.source.title),
                paragraph('مقصد: '+(preview.target ? preview.target.code+' | '+preview.target.title : payload.title)),
                table(['مدرک منتقل‌شونده'],preview.documents.map(d=>[d.title])));
            if (operation==='merge') box.append(table(['فیلد','مبدأ','مقصد'],['official_number','signed_fa','starts_fa','ends_fa','notice_fa','amount','currency'].map(
                key=>[label[key],value(preview.source[key],true),value(preview.target[key],true)])),
                paragraph('مقادیر اصلی پروندهٔ مقصد حفظ می‌شود. طرفین و سوابق مبدأ به مقصد منتقل می‌شوند و پرونده به بازبینی برمی‌گردد.'));
            const confirm=fieldsForm([['confirm','checkbox',[],true,operation==='merge'?'ادغام با حفظ مقادیر مقصد را تأیید می‌کنم':'انتقال مدارک بالا را تأیید می‌کنم']],{});
            box.append(submit(confirm,async fields=>{
                const r=await write(operation,{...payload,confirmed:fields.confirm,keep_target_values:operation==='merge',
                    revision:preview.revision,contract_version:preview.source.lock_version,target_version:preview.target && preview.target.lock_version});
                caseId=r.data.target_id; tab='documents';
            },'تأیید و اجرا'));
        },'پیش‌نمایش',false));
    }
    async function archiveView(target,offset) {
        const row=caseData.contract;
        const sources=await get('source_list',{contract_id:caseId,offset});
        target.append(heading('بایگانی و منشأ'),toolbar(
            caps.can_update && button('ویرایش اطلاعات بایگانی',t=>editor('ویرایش اطلاعات بایگانی',archiveSpecs,row,'update',caseParams(),t)),
            caps.can_update && button(row.archived_at?'بازگردانی از بایگانی':'بایگانی پرونده',async()=>{
                await write(row.archived_at?'unarchive':'archive',caseParams()); await render();
            }),caps.can_delete && button('حذف نرم',t=>{
                openDialog('حذف نرم پرونده',t);
                box.append(submit(fieldsForm([['reason','area',[],true]],{}),async fields=>{
                    await write('delete',{...caseParams(),...fields}); active='list'; caseId=null;
                }));
            }),caps.can_create && caps.can_update && button('تفکیک پرونده',t=>structureDialog('split',t)),
            caps.can_update && button('انتقال مدرک',t=>structureDialog('move_document',t)),
            caps.can_update && button('ادغام پرونده',t=>structureDialog('merge',t))));
        details(target,row,archiveSpecs.map(s=>s[0]).filter(k=>k!=='reason'));
        if (row.hold_at) target.append(paragraph('ثبت منع امحا | '+owner(row.hold_by)+' | '+displayStamp(row.hold_at)));
        target.append(heading('مدارک مبدأ'),table(['شناسه','بسته','مسیر نسبی','پیشنهادها و منشأ'],sources.items.map(r=>[
            r.doc_id,r.bundle_id,r.relative_path,button('مشاهده',t=>openDialog('فرادادهٔ مبدأ',t).append($('<pre dir="auto">').text(pretty(r.metadata))))
        ])));
        pager(target,offset,sources.more,position=>render(position));
    }
    function pretty(raw) {
        try { return JSON.stringify(JSON.parse(raw),null,2); } catch (_) { return value(raw); }
    }
    function displayStamp(stamp) {
        if (!stamp) return '—';
        const date=new Date(stamp.replace(' ','T')+'Z');
        return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('fa-IR',{timeZone:'Asia/Tehran', dateStyle:'short',timeStyle:'short'}).format(date) : stamp;
    }
    async function auditView(target,offset,evidenceOffset) {
        const [rows,evidence]=await Promise.all([get('audit_list',{contract_id:caseId,offset}),get('evidence_list',{contract_id:caseId,offset:evidenceOffset})]);
        target.append(heading('تاریخچهٔ تغییرات'),table(['زمان تهران','کاربر','عملیات','جزئیات'],rows.items.map(r=>[
            displayStamp(r.created_at),owner(r.actor_usr_uid),
            {create:'ایجاد',update:'ویرایش',delete:'حذف نرم'}[r.action] || r.action,
            button('قبل و بعد',t=>openDialog('تغییر ثبت‌شده',t).append(paragraph(r.entity_type+' | '+r.entity_id),
                heading('شرح عملیات'),$('<pre dir="auto">').text(pretty(r.metadata)),
                heading('قبل'),$('<pre dir="auto">').text(pretty(r.before_data)),heading('بعد'),$('<pre dir="auto">').text(pretty(r.after_data))))
        ])));
        pager(target,offset,rows.more,position=>render(position,0));
        target.append(heading('شواهد بازبینی'),table(['فیلد','نسخهٔ شاهد','صفحه یا بند','نتیجه','مقدار خام','مقدار تأییدشده','کاربر و زمان'],evidence.items.map(r=>[
            label[r.field_name] || r.field_name,r.version_id,r.locator,value(r.outcome,true),r.raw_value,r.verified_value,
            owner(r.created_by)+' | '+displayStamp(r.created_at)
        ])));
        pager(target,evidenceOffset,evidence.more,position=>render(0,position));
    }
    async function caseView(target,offset,secondary) {
        const openingId=caseId,detail=await get('get',{contract_id:openingId});
        if(String(caseId)!==String(openingId))return;
        caseData=detail;const row=detail.contract;
        const goTab=key=>{tab=key;return render();};
        const extras=menu('بیشتر',[
            ['دریافت بستهٔ پرونده',()=>transfer('export_case',{contract_id:openingId})],
            caps.can_create&&['افزودن یادآوری',t=>actionEditor(null,t)],
            ['یادآوری‌های این پرونده',()=>goTab('followup')],['الحاقیه و تغییر مبلغ یا مدت',()=>goTab('amendments')],
            ['تعهدات، بیمه و تضمین‌ها',()=>goTab('obligations')],['بایگانی و اصلاح ساختار',()=>goTab('archive')],
            ['تاریخچهٔ تغییرات و شواهد',()=>goTab('audit')],['دسته‌های پرونده',()=>goTab('categories')],
            caps.can_update&&['بازبینی و تأیید اطلاعات',verifyDialog]
        ]);
        const meta=$('<div class="ct-case-meta">').append($('<span>').text(detail.parties.filter(p=>p.side==='other').map(p=>p.name).join('، ')||'طرف قرارداد ثبت نشده'),
            bidi(row.official_number||row.code,'auto'),row.review_status!=='verified'?badge(row.review_status,row.review_status==='provisional'?'اطلاعات بررسی‌نشده':null):null,
            row.archived_at?badge('archived'):null);
        if(row.ends_fa)meta.append(badge(row.time_status,'پایان: '+UI.persianDigits(row.ends_fa)));
        target.append(iconButton('بازگشت به قراردادها',()=>navigate('list'),'back','ct-text-link ct-back'),
            $('<div class="ct-case-header">').append($('<div class="ct-case-identity">').append(heading(row.title).attr('dir','auto'),meta),extras));
        if(row.merged_into){target.append(paragraph('این پرونده ادغام شده است.'),caseLink(row.merged_into,'رفتن به پروندهٔ مقصد'));await auditView(target,offset,secondary);return;}
        if(detail.next_action)target.append($('<div class="ct-case-notice">').append(icon('clock'),
            paragraph('یادآوری: '+detail.next_action.title+' · '+(detail.next_action.due_fa?UI.persianDigits(detail.next_action.due_fa):'بدون موعد')),
            button('مشاهده',()=>goTab('followup'))));
        if(row.notice_fa)target.append(paragraph('مهلت اخطار: '+UI.persianDigits(row.notice_fa)).addClass('ct-deadline-note'));
        const tabs=$('<nav class="ct-case-tabs" role="tablist" aria-label="بخش‌های پرونده">');
        [['documents','مدارک',detail.summary.documents],['history','سوابق',detail.summary.history],['spec','مشخصات',null]].forEach(([key,title,count])=>{
            const item=button(title+(count===null?'':' '+UI.persianDigits(count)),()=>goTab(key)).attr({role:'tab','aria-selected':String(tab===key),'aria-controls':'ct-case-content',tabindex:tab===key?0:-1,id:'ct-tab-'+key});
            item.on('keydown',event=>{
                if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
                event.preventDefault();const buttons=tabs.children('button'),index=buttons.index(item),next=event.key==='Home'?0:event.key==='End'?2:(index+(event.key==='ArrowLeft'?1:-1)+3)%3;
                buttons.eq(next).trigger('click');setTimeout(()=>root.find('#ct-tab-'+['documents','history','spec'][next]).trigger('focus'),0);
            });tabs.append(item);
        });
        const contents=$('<section id="ct-case-content">').attr(['documents','history','spec'].includes(tab)?{role:'tabpanel','aria-labelledby':'ct-tab-'+tab}:{});
        target.append(tabs,contents);
        if(tab==='documents')await documentsView(contents,offset);
        if(tab==='history')await historyView(contents,offset);
        if(tab==='spec'||tab==='parties'){
            contents.append(sectionHead('مشخصات قرارداد',caps.can_update&&iconButton('ویرایش مشخصات',t=>contractEditor(row,t),'edit')));
            details(contents,row,['title','official_number','contract_type','signed_fa','ends_fa','original_location']);
            const parties=$('<div>');partiesView(parties);contents.append(disclosure('طرفین و ارتباط با معادن',parties.children(),true));
            const extra=$('<div>');details(extra,row,mainSpecs.map(s=>s[0]).filter(k=>!['title','official_number','contract_type','signed_fa','ends_fa'].includes(k)));
            contents.append(disclosure('اطلاعات تکمیلی',extra.children()));
            if(detail.duplicate_numbers.length)contents.append(paragraph('شمارهٔ رسمی در پروندهٔ دیگری با طرف خودی مشترک ثبت شده است.').addClass('ct-note'),toolbar(...detail.duplicate_numbers.map(r=>caseLink(r.id,r.title))));
        }
        if(tab==='followup')await actionsView(contents,{contract_id:openingId},offset);
        if(tab==='obligations')obligationsView(contents);
        if(tab==='amendments')amendmentsView(contents);
        if(tab==='archive')await archiveView(contents,offset);
        if(tab==='audit')await auditView(contents,offset,secondary);
        if(tab==='categories')await categoriesView(contents,openingId);
    }
    async function importView(target) {
        const form=fieldsForm([['manifest_hash','text',[],true]],{manifest_hash:manifestHash}).addClass('ct-filter-panel');
        form.find('input').attr('pattern','[a-f0-9]{64}');
        form.append(toolbar($('<button type="submit" class="ec-btn ec-primary">').text('بررسی فهرست و وضعیت')));
        form.on('submit',e=>{
            e.preventDefault();
            e.stopPropagation();
            manifestHash=readForm(form).manifest_hash.trim();
            importOffset=0;
            render();
        });
        target.append(sectionHead('ورود و بازبینی آرشیو',
            button('پرونده‌های موقت',()=>openContractList({review_status:'provisional',archive:'all'}))),
            paragraph('شناسهٔ ۶۴ نویسه‌ای که ابزار آماده‌سازی آرشیو داده است وارد کنید.').addClass('ct-field-note'),form);
        if (!manifestHash) return;
        const hash=manifestHash;
        let preview,progress;
        try {
            [preview,progress]=await Promise.all([
                get('import_preview',{manifest_hash:hash,offset:importOffset}),
                get('import_status',{manifest_hash:hash,offset:importOffset})
            ]);
        } catch (error) {
            target.append(emptyState('فهرست آرشیو باز نشد.',errorText(error)));
            return;
        }
        const cursor=progress.batch ? Number(progress.batch.cursor) : 0;
        const complete=cursor>=Number(preview.total);
        const progressPanel=dashboardPanel('پیشرفت ورود',[
            $('<progress class="ct-import-progress" aria-label="پیشرفت ورود آرشیو">').attr({max:preview.total,value:cursor}),
            paragraph(UI.persianDigits(cursor)+' از '+UI.persianDigits(preview.total)+' قلم تعیین تکلیف شد. '+
                UI.persianDigits(preview.bundles)+' بستهٔ دارای مدرک مرتبط.'),
            complete ? badge('imported','پردازش فهرست کامل شد') : null
        ]);
        target.append(progressPanel,disclosure('خلاصهٔ فهرست مبدأ',
            table(['وضعیت پیش‌نمایش','تعداد'],Object.entries(preview.counts).map(([k,v])=>[
                badge(k,status[k] || ({same_hash:'دارای هم‌هش',archive_member:'عضو آرشیو'}[k] || k)),UI.persianDigits(v)
            ]),{name:'وضعیت پیش‌نمایش آرشیو'}),!progress.batch));
        if (caps.can_create && caps.can_update && !complete) {
            const caption='ورود '+UI.persianDigits(Math.min(25,preview.total-cursor))+' قلم بعدی';
            progressPanel.append(button(caption,async trigger=>{
                $(trigger).prop('disabled',true).text('در حال ورود…');
                try {
                    const r=await write('import_apply_batch',{manifest_hash:hash,cursor});
                    message(UI.persianDigits(r.data.cursor)+' قلم از فهرست پردازش شد.');
                    await render();
                } finally {$(trigger).prop('disabled',false).text(caption);}
            },'ec-primary'));
        }
        const mapped=new Map(progress.items.map(r=>[r.doc_id,r]));
        target.append(table(['شناسهٔ مدرک','مسیر','وضعیت','پرونده یا خطا'],preview.items.map(r=>{
            const row=mapped.get(r.doc_id);
            const available = row && ['ungrouped','reference','container'].includes(row.status);
            const connectable = available && row.status!=='container' && caps.can_update && /\.(pdf|docx?|xlsx?|jpe?g|png|bmp)$/i.test(r.filename);
            const fileButtons = available && toolbar(button('دریافت اصل',()=>transfer('source_download',{id:row.id})),
                connectable && button('اتصال به پرونده',t=>{
                    openDialog('اتصال مدرک مبدأ',t);
                    box.append(submit(fieldsForm([['target_id','text',[],true,'کد داخلی یا شناسهٔ پرونده']],{}),fields=>
                        write('source_attach',{id:row.id,contract_id:UI.latinDigits(fields.target_id).replace(/^CTR-0*/i,'')})));
                }));
            return [bidi(r.doc_id),$('<span dir="auto">').text(r.relative_path),badge(row ? row.status : r.status),
                row && row.contract_id ? caseLink(row.contract_id) : fileButtons || r.error || '—'];
        })));
        pager(target,importOffset,importOffset+25<preview.total,position=>{importOffset=position;return render();});
    }
    async function render(offset=0,secondary=0,focusView=false) {
        const current=++generation;
        const page=active;
        navDraw();
        const target=$('<div>');
        try {
            if (page==='dashboard') await dashboard(target);
            if (page==='list') await listView(target);
            if (page==='case') await caseView(target,offset,secondary);
            if (page==='mine' || page==='actions') await remindersView(target);
            if (page==='without_due' || page==='mine_without_due') {
                target.append(button('نمایش همهٔ موعدها',()=>navigate(page==='mine_without_due'?'mine':'actions')));
                await actionsView(target,{mine:page==='mine_without_due',open_only:true,without_due:true},actionOffset);
            }
            if (page==='import') await importView(target);
            if (page==='categories') await categoriesView(target,null);
            if (current===generation) {
                const focusText=body.find(':focus').text();
                body.empty().append(target);
                if (page==='list' && lastListFocus==='results') {
                    lastListFocus=null;
                    target.find('.ct-results').trigger('focus');
                } else if (focusView) {
                    target.find('h2').first().attr('tabindex','-1').trigger('focus');
                } else if (focusText) body.find('button').filter(function () {return $(this).text()===focusText;}).first().trigger('focus');
            }
        } catch (e) {
            if (current===generation) {
                message(errorText(e),true);
                body.empty().append(emptyState('این بخش بارگیری نشد.'),button('تلاش دوباره',()=>render(offset,secondary)));
            }
        }
    }
    root.find('.ct-close').on('click',()=>modal.close());
    get('lookups').then(data=>{
        lookups=data;
        if(Number(data.ui_version)!==4)throw new Error('نسخهٔ API و پنل هماهنگ نیست؛ بستهٔ کامل به‌روزرسانی را نصب کنید.');
        root.find('.ct-brand-icon').append(icon('archive'));
        return render();
    }).catch(e=>message(errorText(e),true));
    const refresh = window.setInterval(()=>{
        if (!document.contains(root[0])) {
            window.clearInterval(refresh);
            return;
        }
        const live=['dashboard','actions','mine','without_due','mine_without_due'];
        if (document.visibilityState==='visible' && live.includes(active) && !modal.isOpen()) render();
    },60000);
}(window.jQuery,window.EmcoreUI));
