(function ($, UI) {
  'use strict';
  if (!$ || !UI) return;
  $(function () { $('.emcore-minutes').each(function () { initialize($(this)); }); });
  function initialize(root) {
    if (root.data('minutes-ready')) return; root.data('minutes-ready', true);
    var apiUrl=root.attr('data-api'), token='', permissions={}, lookups={}, rows=[], page=1, pages=1, sort='id', order='desc';
    var current=null, mode='create', saving=false, dirty=false, populating=false, createRequest='', createPayload=null;
    var loadGeneration=0, previewGeneration=0, dialogGeneration=0, replacement=null, queueContext=null, queueFailed=false;
    function el(id){return root.find('#mm-'+id);}
    function api(action,data,options){
      options=options || {}; data=$.extend({},data || {},{action:action});
      return $.ajax({url:apiUrl,type:'POST',dataType:'json',data:data,headers:token?{'X-CSRF-Token':token}:{}});
    }
    function message(text,error,form){el(form?'form-error':'notice').prop('hidden',!text).toggleClass('error',!!error).text(text || '');}
    function can(key){return Number(permissions['can_'+key])===1;}
    function today(){return UI.todayFromGregorian(lookups.today_gregorian);}
    var calendars=[];
    root.find('#mm-date,#mm-date-from,#mm-date-to').each(function(){calendars.push(new UI.DatePicker(this,{today:today}));});
    function changed(){if(!populating){dirty=true; createPayload=null;applyDialogPermissions();}}
    function userSearch(search){return api('search_users',{search:search});}
    function ensureOfficers(){
      if(populating)return;
      var list=present.get();chair.get().concat(secretary.get()).forEach(function(p){if(!list.some(function(x){return UI.personKey(x)===UI.personKey(p);}))list.push(p);});
      present.set(list);changed();
    }
    var chair=new UI.ParticipantPicker(el('chair'),{label:'جست‌وجو یا ثبت نام رئیس',search:userSearch,onChange:ensureOfficers});
    var secretary=new UI.ParticipantPicker(el('secretary'),{label:'جست‌وجو یا ثبت نام دبیر',search:userSearch,onChange:ensureOfficers});
    var present=new UI.ParticipantPicker(el('present'),{label:'جست‌وجو یا ثبت نام حاضرین',multiple:true,search:userSearch,onChange:changed});
    var absent=new UI.ParticipantPicker(el('absent'),{label:'جست‌وجو یا ثبت نام غایبین',multiple:true,search:userSearch,onChange:changed});
    var filterPerson=new UI.ParticipantPicker(el('person-filter'),{label:'جست‌وجوی افراد ثبت‌شده در جلسات',allowExternal:false,search:function(search){return api('search_participants',{search:search});}});
    var modal=new UI.Modal(el('overlay'),{beforeClose:function(){
      if(saving || queue.isRunning()){message('تا پایان ذخیره یا بارگذاری، پنجره را باز نگه دارید.',true,true);return false;}
      if(dirty && !window.confirm('تغییرات ذخیره نشده‌اند. پنجره بسته شود؟'))return false;
      calendars.forEach(function(c){c.close();});dialogGeneration+=1;return true;
    }});
    function fillCompanies(){
      var old=el('company-filter').val();el('company-filter').empty().append($('<option>').val('').text('همه شرکت‌ها'));
      (lookups.companies || []).forEach(function(c){el('company-filter').append($('<option>').val(c.id).text(c.name_fa));});el('company-filter').val(old);
      el('company').empty().append($('<option>').val('').text('شرکت را انتخاب کنید'));
      (lookups.companies || []).forEach(function(c){if(Number(c.is_active)===1 || el('legacy').prop('checked'))el('company').append($('<option>').val(c.id).text(c.name_fa+(c.code?' ('+c.code+')':'')));});
      el('code-settings').prop('hidden',!lookups.can_manage_codes);el('code-rows').empty();
      if(lookups.can_manage_codes)(lookups.companies || []).forEach(function(c){
        var row=$('<div>').addClass('mm-code-row'), input=$('<input>').attr({type:'text',maxlength:32,'aria-label':'کد '+c.name_fa,dir:'ltr'}).val(c.code || '').prop('disabled',!!c.locked_at);
        var b=$('<button>').attr('type','button').addClass('ec-btn ec-small').text(c.locked_at?'شماره صادر شده؛ کد ثابت است':'ذخیره کد').prop('disabled',!!c.locked_at).on('click',function(){
          b.prop('disabled',true);api('set_company_code',{company_id:c.id,code:input.val()}).done(function(){loadLookups().done(function(){message('کد شرکت ذخیره شد.');});}).fail(function(xhr){message(UI.errorText(xhr),true);}).always(function(){b.prop('disabled',false);});
        });el('code-rows').append(row.append($('<span>').text(c.name_fa),input,b));
      });
    }
    function loadLookups(){return api('lookups').done(function(r){token=r.csrf_token;permissions=r.permissions || {};lookups=r.data;el('create').prop('hidden',!can('create'));fillCompanies();});}
    function query(){return{search:el('search').val(),company_id:el('company-filter').val(),date_from:UI.latinDigits(el('date-from').val()),date_to:UI.latinDigits(el('date-to').val()),
      scan_status:el('scan-filter').val(),record_origin:el('origin-filter').val(),metadata_complete:el('quality-filter').val(),person_key:(filterPerson.get()[0] || {}).person_key || ''};}
    var appliedFilters={};
    function loadRows(){
      var generation=++loadGeneration;el('loading').text('در حال دریافت…');
      return api('list',$.extend({},appliedFilters,{page:page,page_size:25,sort_by:sort,sort_order:order})).done(function(r){
        if(generation!==loadGeneration)return;rows=r.data;token=r.csrf_token;permissions=r.permissions;page=r.pagination.page;pages=r.pagination.total_pages;
        el('total').text(UI.persianDigits(r.summary.total));el('archived').text(UI.persianDigits(r.summary.archived));el('awaiting').text(UI.persianDigits(r.summary.awaiting_scan));el('incomplete').text(UI.persianDigits(r.summary.incomplete));
        renderRows();el('page-label').text('صفحه '+UI.persianDigits(page)+' از '+UI.persianDigits(pages)+'، '+UI.persianDigits(r.pagination.total)+' صورت‌جلسه');
        el('prev').prop('disabled',page<=1);el('next').prop('disabled',page>=pages);el('create').prop('hidden',!can('create'));
        root.find('th[data-sort]').each(function(){var th=$(this);th.attr('aria-sort',th.attr('data-sort')===sort?(order==='asc'?'ascending':'descending'):'none');});
      }).fail(function(xhr){if(generation===loadGeneration){message(UI.errorText(xhr),true);el('rows').empty().append($('<tr>').append($('<td>').attr('colspan',9).text('دریافت فهرست ناموفق بود؛ دوباره فیلترها را اعمال کنید.')));}}).always(function(){if(generation===loadGeneration)el('loading').text('');});
    }
    function badge(text,style){return $('<span>').addClass('ec-badge '+(style || '')).text(text);}
    function scanBadge(r){return badge(r.scan_status==='archived'?'اسکن بایگانی شده':'آمادهٔ بارگذاری اسکن',r.scan_status==='archived'?'good':'warning');}
    function actionButton(text,fn,style){return $('<button>').attr('type','button').addClass('ec-btn ec-small '+(style || '')).text(text).on('click',fn);}
    function renderRows(){
      el('rows').empty();if(!rows.length){el('rows').append($('<tr>').append($('<td>').attr('colspan',9).text('صورت‌جلسه‌ای برای این فیلترها ثبت نشده است.')));return;}
      rows.forEach(function(r){
        var tr=$('<tr>'), title=$('<td>').addClass('mm-title-cell').append($('<div>').text(r.title));
        if(r.record_origin==='legacy')title.append(badge('آرشیو قدیمی'));if(!Number(r.metadata_complete))title.append(badge('اطلاعات ناقص','warning'));
        tr.append($('<td>').text(r.company_name_snapshot),$('<td>').addClass('mm-number').text(r.meeting_number),title,$('<td>').text(r.meeting_date_fa?UI.persianDigits(r.meeting_date_fa):'نامشخص'),$('<td>').text(r.chair_name || 'نامشخص'),$('<td>').text(r.secretary_name || 'نامشخص'),$('<td>').append(scanBadge(r)),$('<td>').text(UI.persianDigits(r.attachment_count)));
        var actions=$('<div>').addClass('ec-actions').append(actionButton('مشاهده',function(){openRecord(r.id,'view',this);}));
        if(can('update'))actions.append(actionButton('ویرایش',function(){openRecord(r.id,'edit',this);}),actionButton('بارگذاری',function(){openRecord(r.id,'edit',this,true);},'ec-primary'));
        if(can('delete'))actions.append(actionButton('حذف',function(){deleteRecord(r);},'ec-danger'));
        tr.append($('<td>').append(actions));el('rows').append(tr);
      });
    }
    function preview(){
      var generation=++previewGeneration;
      if(current || el('legacy').prop('checked'))return;
      el('number').val('');var company=el('company').val(), date=UI.latinDigits(el('date').val());
      if(!company || !date)return;
      api('number_preview',{company_id:company,meeting_date_fa:date}).done(function(r){if(generation===previewGeneration && !current){el('number').val(r.data.meeting_number);el('number-help').text('پیش‌نمایش؛ شمارهٔ قطعی هنگام ذخیره صادر می‌شود.');}}).fail(function(xhr){if(generation===previewGeneration)el('number-help').text(UI.errorText(xhr));});
    }
    function resetUpload(){replacement=null;queueContext=null;queueFailed=false;queue.prepare([]);el('file-input').val('').prop('multiple',true);el('file-role').val('scan');el('replace-row').prop('hidden',true);el('replace-reason').val('');el('progress').prop('hidden',true);el('file-status').text('می‌توانید چند فایل را هم‌زمان انتخاب کنید.');setAccept();}
    function openCreate(trigger){
      dialogGeneration+=1;previewGeneration+=1;current=null;mode='create';createRequest=UI.requestId();createPayload=null;populating=true;
      el('form')[0].reset();[chair,secretary,present,absent].forEach(function(p){p.set([]);});fillCompanies();el('date').val(today());el('number').val('');resetUpload();
      dirty=false;populating=false;message('',false,true);el('dialog-title').text('ثبت صورت‌جلسه');el('record-state').empty();el('upload-section').prop('hidden',true);applyDialogPermissions();modal.open(trigger);
    }
    function openRecord(id,newMode,trigger,uploadFocus){
      var generation=++dialogGeneration;
      api('get',{id:id}).done(function(r){if(generation!==dialogGeneration)return;current=r.data;mode=newMode;populateRecord();resetUpload();message('',false,true);modal.open(trigger);if(uploadFocus && el('upload').is(':visible'))el('file-input').trigger('focus');}).fail(function(xhr){message(UI.errorText(xhr),true);});
    }
    function populateRecord(){
      populating=true;previewGeneration+=1;el('legacy').prop('checked',current.record_origin==='legacy');fillCompanies();
      if(!el('company').find('option').filter(function(){return String($(this).val())===String(current.company_id);}).length)el('company').append($('<option>').val(current.company_id).text(current.company_name_snapshot));
      el('company').val(current.company_id);el('number').val(current.meeting_number);el('title').val(current.title);el('date').val(UI.persianDigits(current.meeting_date_fa || ''));
      el('start').val(UI.persianDigits((current.start_time || '').slice(0,5)));el('end').val(UI.persianDigits((current.end_time || '').slice(0,5)));el('next-day').prop('checked',!!Number(current.ends_next_day));el('agenda').val(current.agenda);el('notes').val(current.notes);
      var people=current.participants || [];chair.set(people.filter(function(p){return Number(p.is_chair)===1;}));secretary.set(people.filter(function(p){return Number(p.is_secretary)===1;}));present.set(people.filter(function(p){return p.attendance==='present';}));absent.set(people.filter(function(p){return p.attendance==='absent';}));
      el('dialog-title').text(mode==='view'?'مشاهدهٔ صورت‌جلسه':'ویرایش صورت‌جلسه');el('number-help').text('شمارهٔ صادرشده ثابت است؛ اصلاح تاریخ آن را تغییر نمی‌دهد.');
      el('upload-section').prop('hidden',false);updateState();renderFiles();dirty=false;populating=false;applyDialogPermissions();
    }
    function updateState(){el('record-state').empty().append(scanBadge(current),$('<span>').text(' '),badge(Number(current.metadata_complete)?'اطلاعات سربرگ کامل':'اطلاعات سربرگ ناقص',Number(current.metadata_complete)?'good':'warning'));}
    function applyDialogPermissions(){
      var edit=(mode==='create'?can('create'):mode==='edit' && can('update')) && !saving && !queue.isRunning();
      el('form').find('input,select,textarea,button').prop('disabled',!edit);[chair,secretary,present,absent].forEach(function(p){p.setDisabled(!edit);});
      if(current)el('company').add(el('legacy')).prop('disabled',true);el('number').prop('readonly',!!current || !el('legacy').prop('checked'));
      el('save').prop('hidden',mode==='view' || (current?!can('update'):!can('create'))).prop('disabled',!edit);
      var uploads=!!current && can('update') && mode==='edit';el('upload-controls').prop('hidden',!uploads);
      el('upload-controls').find('input,select,button').prop('disabled',saving || queue.isRunning() || !lookups.storage_ready || dirty);
      if(replacement)el('file-role').prop('disabled',true);
      el('replace-row').find('input,button').prop('disabled',saving || queue.isRunning());
      el('close').add(el('cancel')).prop('disabled',saving || queue.isRunning());
      el('file-list').add(el('history-list')).find('button').prop('disabled',saving || queue.isRunning() || dirty);
      if(uploads && !lookups.storage_ready)el('file-status').text('مخزن فایل آماده نیست؛ مدیر سامانه باید تنظیمات آن را بررسی کند.');
      else if(uploads && dirty)el('file-status').text('پیش از بارگذاری، تغییرات مشخصات را ذخیره کنید.');
    }
    function makePeople(){
      var list=present.get(), c=chair.get()[0], s=secretary.get()[0];
      [c,s].forEach(function(p){if(p && !list.some(function(x){return UI.personKey(x)===UI.personKey(p);}))list.push(p);});
      var keys={};list=list.map(function(p){var k=UI.personKey(p);keys[k]=true;return $.extend({},p,{attendance:'present',is_chair:c && UI.personKey(c)===k?1:0,is_secretary:s && UI.personKey(s)===k?1:0});});
      absent.get().forEach(function(p){if(keys[UI.personKey(p)])throw new Error('«'+p.name_snapshot+'» هم حاضر و هم غایب انتخاب شده است.');list.push($.extend({},p,{attendance:'absent',is_chair:0,is_secretary:0}));});return list;
    }
    function payload(){var p={title:el('title').val(),meeting_date_fa:UI.latinDigits(el('date').val()),start_time:UI.latinDigits(el('start').val()),end_time:UI.latinDigits(el('end').val()),ends_next_day:el('next-day').prop('checked')?1:0,agenda:el('agenda').val(),notes:el('notes').val(),participants:JSON.stringify(makePeople())};
      if(current){p.id=current.id;p.lock_version=current.lock_version;}else{p.company_id=el('company').val();p.record_origin=el('legacy').prop('checked')?'legacy':'managed';p.meeting_number=p.record_origin==='legacy'?el('number').val():'';p.request_id=createRequest;}return p;}
    el('form').on('submit',function(e){
      e.preventDefault();if(saving || queue.isRunning() || mode==='view')return;
      var data;try{data=createPayload || payload();}catch(error){message(error.message,true,true);return;}
      if(!current)createPayload=data;
      saving=true;message('',false,true);applyDialogPermissions();
      api(current?'update':'create',data).done(function(r){current=r.data;mode='edit';populateRecord();resetUpload();message('صورت‌جلسه ذخیره شد. اکنون می‌توانید اسکن آن را بارگذاری کنید.',false,true);loadRows();})
        .fail(function(xhr){message(UI.errorText(xhr),true,true);if(xhr.status>=400 && xhr.status<500 && xhr.status!==409)createPayload=null;})
        .always(function(){saving=false;applyDialogPermissions();});
    });
    el('form').on('input change','input,textarea,select',function(){changed();applyDialogPermissions();});
    el('legacy').on('change',function(){
      if(current)return;var old=el('company').val();fillCompanies();el('company').val(old);el('number').val('');el('date').val($(this).prop('checked')?'':today());
      el('number-help').text($(this).prop('checked')?'شمارهٔ اصلی آرشیو را وارد کنید؛ سایر اطلاعات می‌توانند نامشخص باشند.':'شمارهٔ قطعی هنگام ذخیره صادر می‌شود.');applyDialogPermissions();preview();
    });el('company').add(el('date')).on('change',preview);
    function setAccept(){var role=el('file-role').val(), extensions=role==='scan'?lookups.scan_extensions:lookups.attachment_extensions;el('file-input').attr('accept',(extensions || ['pdf','jpg','jpeg','png']).map(function(x){return'.'+x;}).join(','));}
    el('file-role').on('change',function(){resetSelection();setAccept();});
    function resetSelection(){queue.prepare([]);queueFailed=false;queueContext=null;el('file-input').val('');el('file-status').text('می‌توانید چند فایل را هم‌زمان انتخاب کنید.');}
    el('file-input').on('change',function(){
      var files=this.files;queueFailed=false;queue.prepare([]);queueContext=null;
      el('file-status').text(files.length?UI.persianDigits(files.length)+' فایل انتخاب شد؛ حداکثر هر فایل '+UI.fileSize(lookups.max_upload_bytes):'فایلی انتخاب نشده است.');
    });
    var queue=new UI.UploadQueue({send:function(file,request,onProgress){
      var data=new FormData();data.append('action',queueContext.replacement?'replace_file':'upload_file');data.append('id',queueContext.id);data.append('lock_version',current.lock_version);data.append('file_role',queueContext.role);data.append('request_id',request);data.append('file',file);
      if(queueContext.replacement){data.append('file_id',queueContext.replacement);data.append('replacement_reason',queueContext.reason);}
      return $.ajax({url:apiUrl,type:'POST',dataType:'json',data:data,processData:false,contentType:false,headers:{'X-CSRF-Token':token},xhr:function(){var xhr=$.ajaxSettings.xhr();if(xhr.upload)xhr.upload.addEventListener('progress',onProgress);return xhr;}});
    },onProgress:function(percent,name,index,total){
      applyDialogPermissions();el('progress').prop('hidden',false);el('progress-bar').val(percent).text(UI.persianDigits(Math.round(percent))+'٪');el('progress-value').text(UI.persianDigits(Math.round(percent))+'٪');el('progress-label').text('فایل '+UI.persianDigits(index+1)+' از '+UI.persianDigits(total)+'؛ '+name);
    },onSuccess:function(response){current=response.data;updateState();renderFiles();},onError:function(xhr,name){
      queueFailed=true;message('بارگذاری «'+name+'» متوقف شد. '+UI.errorText(xhr),true,true);el('file-status').text('فایل‌های موفق حفظ شدند؛ برای ادامه، دوباره بارگذاری را بزنید.');
      if(xhr.status===409){api('get',{id:current.id}).done(function(r){current=r.data;updateState();renderFiles();});}
      applyDialogPermissions();loadRows();
    },onFinish:function(){queueFailed=false;replacement=null;el('replace-row').prop('hidden',true);el('replace-reason').val('');el('file-input').val('').prop('multiple',true);el('file-role').prop('disabled',false);
      el('progress-bar').val(100);el('progress-value').text('۱۰۰٪');el('progress-label').text('بارگذاری فایل‌ها کامل شد.');el('file-status').text('فایل‌ها با موفقیت بارگذاری شدند.');message('',false,true);applyDialogPermissions();loadRows();}});
    el('upload').on('click',function(){
      if(!current || saving || queue.isRunning() || dirty || !can('update'))return;
      if(!queueFailed){var files=el('file-input')[0].files;if(!files.length){message('ابتدا فایل را انتخاب کنید.',true,true);return;}
        if(replacement && !$.trim(el('replace-reason').val())){message('دلیل جایگزینی اسکن الزامی است.',true,true);return;}
        if(replacement && files.length!==1){message('برای جایگزینی، یک فایل انتخاب کنید.',true,true);return;}
        var exts=el('file-role').val()==='scan'?lookups.scan_extensions:lookups.attachment_extensions;
        for(var i=0;i<files.length;i+=1){var ext=files[i].name.split('.').pop().toLowerCase();if(files[i].size<=0 || files[i].size>lookups.max_upload_bytes || exts.indexOf(ext)<0){message('اندازه یا نوع فایل «'+files[i].name+'» مجاز نیست.',true,true);return;}}
        queueContext={id:current.id,role:el('file-role').val(),replacement:replacement,reason:el('replace-reason').val()};queue.prepare(files);
      }message('',false,true);queue.start();applyDialogPermissions();
    });
    function renderFiles(){
      el('file-list').empty();el('history-list').empty();var history=0,active=0;
      (current.files || []).forEach(function(f){
        var old=!!f.superseded_at,item=$('<div>').addClass('ec-file'), info=$('<div>').append($('<div>').addClass('ec-file-name').text(f.original_filename),$('<small>').text((f.file_role==='scan'?'اسکن صورت‌جلسه':'پیوست')+' · '+String(f.extension).toUpperCase()+' · '+UI.fileSize(Number(f.file_size))));
        if(f.replacement_reason)info.append($('<small>').text('دلیل جایگزینی: '+f.replacement_reason));
        var buttons=$('<div>').addClass('ec-actions').append(actionButton('دانلود',function(){download(f);}));
        if(!old && f.file_role==='scan' && can('update') && mode==='edit')buttons.append(actionButton('جایگزینی اسکن',function(){
          if(queue.isRunning() || saving)return;replacement=f.id;resetSelection();el('file-role').val('scan');setAccept();el('file-input').prop('multiple',false);el('replace-row').prop('hidden',false);el('replace-reason').val('');applyDialogPermissions();el('replace-reason').trigger('focus');
        }));
        if(!old && can('delete') && mode!=='create')buttons.append(actionButton('حذف',function(){deleteFile(f);},'ec-danger'));
        item.append(info,buttons);if(old){history+=1;el('history-list').append(item);}else{active+=1;el('file-list').append(item);}
      });if(!active)el('file-list').append($('<p>').text('هنوز فایلی بارگذاری نشده است.'));el('file-history').prop('hidden',!history);
      el('file-list').add(el('history-list')).find('button').prop('disabled',queue.isRunning() || saving);
    }
    function download(f){
      // A real POST download preserves the endpoint's contract and session cookie.
      var form=$('<form>').attr({method:'POST',action:apiUrl,target:'_blank'}).prop('hidden',true);
      [{name:'action',value:'download_file'},{name:'file_id',value:f.id}].forEach(function(p){form.append($('<input>').attr({type:'hidden',name:p.name}).val(p.value));});
      root.append(form);form[0].submit();form.remove();
    }
    function deleteFile(f){if(queue.isRunning() || saving || dirty)return;if(!window.confirm('فایل «'+f.original_filename+'» از فهرست فعال حذف شود؟'))return;saving=true;applyDialogPermissions();
      api('delete_file',{id:current.id,file_id:f.id,lock_version:current.lock_version}).done(function(r){current=r.data;updateState();renderFiles();loadRows();}).fail(function(xhr){message(UI.errorText(xhr),true,true);}).always(function(){saving=false;renderFiles();applyDialogPermissions();});}
    function deleteRecord(r){if(!window.confirm('صورت‌جلسهٔ '+r.meeting_number+' از آرشیو فعال حذف شود؟'))return;api('delete',{id:r.id,lock_version:r.lock_version}).done(function(){loadRows();message('صورت‌جلسه از آرشیو فعال حذف شد.');}).fail(function(xhr){message(UI.errorText(xhr),true);});}
    el('cancel-replace').on('click',function(){if(queue.isRunning())return;resetUpload();applyDialogPermissions();});
    el('create').on('click',function(){openCreate(this);});el('close').add(el('cancel')).on('click',function(){modal.close();});
    el('filters').on('submit',function(e){e.preventDefault();appliedFilters=query();page=1;message('');loadRows();});
    el('reset').on('click',function(){el('filters')[0].reset();filterPerson.set([]);appliedFilters={};page=1;sort='id';order='desc';message('');loadRows();});
    el('prev').on('click',function(){if(page>1){page-=1;loadRows();}});el('next').on('click',function(){if(page<pages){page+=1;loadRows();}});
    root.find('th[data-sort] button').on('click',function(){var value=$(this).parent().attr('data-sort');order=sort===value && order==='asc'?'desc':'asc';sort=value;page=1;loadRows();});
    el('filters').find('input,select,button').prop('disabled',true);
    loadLookups().done(function(){el('filters').find('input,select,button').prop('disabled',false);appliedFilters=query();loadRows();}).fail(function(xhr){message(UI.errorText(xhr),true);el('rows').empty().append($('<tr>').append($('<td>').attr('colspan',9).text('دسترسی به آرشیو برقرار نشد.')));});
  }
}(window.jQuery,window.EmcoreUI));
