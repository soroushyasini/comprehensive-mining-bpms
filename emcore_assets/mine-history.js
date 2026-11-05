(function (global, $, UI) {
  'use strict';
  if (!UI || global.EmcoreMineHistory) return;
  var api = '/emcore_api/emcore_mine_history.php', token = '', settings, permissions = {}, mine, trigger;
  var generation = 0, activeCategory = null, editing, createRequest, baseline = '', busy = false, switching = false, saved = false, queue;
  var root = $('<div>').addClass('emcore-ui mh-root').attr({id:'mh-root',dir:'rtl'}).appendTo(document.body);
  function button(text, fn, primary) { return $('<button>').attr('type','button').addClass('ec-btn' + (primary ? ' ec-primary' : '')).text(text).on('click', fn); }
  function icon(name) {
    var ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');
    $(svg).attr({viewBox:'0 0 24 24',fill:'none',stroke:'currentColor','stroke-width':'1.7','stroke-linecap':'round','stroke-linejoin':'round','aria-hidden':'true',focusable:'false'});
    var paths={edit:['M16 3a2.1 2.1 0 0 1 3 3L8 17l-4 1 1-4Z','m14 5 3 3','M20 14v6H3V4h7'],
      delete:['M3 6h18','M9 6V3h6v3','m5 6 1 15h12l1-15','M10 10v7','M14 10v7'],file:['M14 2H5v20h14V7Z','M14 2v6h5','M8 13h8','M8 17h5']};
    (paths[name]||paths.file).forEach(function(d){var path=document.createElementNS(ns,'path');path.setAttribute('d',d);svg.appendChild(path);});return svg;
  }
  function iconButton(label, name, fn) {
    return button('',fn).addClass('mh-icon-button mh-'+name).attr({'aria-label':label,title:label}).append(icon(name));
  }
  // SQL timestamps have no timezone suffix: convert their calendar date without
  // parsing through the browser timezone or shifting the stored clock time.
  function registeredAt(value) {
    var m=/^(\d{4})-(\d{2})-(\d{2})[ T]([0-2]\d):([0-5]\d):([0-5]\d)$/.exec(String(value||''));
    if(!m || Number(m[4])>23)return 'نامشخص';
    var d=new Date(Date.UTC(Number(m[1]),Number(m[2])-1,Number(m[3])));
    if(d.getUTCFullYear()!==Number(m[1]) || d.getUTCMonth()+1!==Number(m[2]) || d.getUTCDate()!==Number(m[3]))return 'نامشخص';
    return UI.todayFromGregorian(m[1]+'-'+m[2]+'-'+m[3])+'، ساعت '+UI.persianDigits(m[4]+':'+m[5]+':'+m[6]);
  }
  function updateAttachmentHints() {
    root.find('.mh-attachment-list:visible').each(function(){
      var overflow=this.scrollHeight>this.clientHeight+1;
      $(this).attr('tabindex',overflow?'0':'-1').siblings('.mh-attachment-hint').prop('hidden',!overflow);
    });
  }
  $(global).on('resize.mineHistory',function(){global.requestAnimationFrame(updateAttachmentHints);});
  if(document.fonts && document.fonts.ready)document.fonts.ready.then(updateAttachmentHints);
  function allowed(cap) { return Number(permissions['can_'+cap]) === 1; }
  function message(host, text, error) { host.empty(); if (text) host.append($('<p>').addClass('ec-message'+(error?' error':'')).attr('role',error?'alert':'status').text(text)); }
  function request(action, data) {
    return $.ajax({url:api,type:'POST',dataType:'json',data:$.extend({action:action},data),headers:{'X-CSRF-Token':token}});
  }
  function error(xhr) { return UI.errorText(xhr); }
  function lookups() {
    return request('lookups').then(function(r){settings=r;token=r.csrf_token;permissions=r.permissions;return r;});
  }
  function dialog(id, title, beforeClose) {
    var overlay=$('<div>').attr({id:id,hidden:true}).addClass('ec-modal').appendTo(root);
    var box=$('<section>').addClass('ec-dialog').attr({role:'dialog','aria-modal':'true','aria-labelledby':id+'-title'}).appendTo(overlay);
    var heading=$('<h2>').attr('id',id+'-title').text(title), head=$('<div>').addClass('ec-dialog-head').appendTo(box);
    var background=[];
    var modal=new UI.Modal(overlay[0],{beforeClose:function(){
      if(beforeClose && beforeClose()===false)return false;
      background.forEach(function(s){s.el.inert=s.inert;});background=[];return true;
    }});
    var openModal=modal.open;modal.open=function(source){
      // The shared modal handles sibling dialogs. Also isolate the existing
      // ProcessMaker panel outside this feature root, restoring before focus.
      root.siblings().each(function(){background.push({el:this,inert:this.inert});this.inert=true;});openModal(source);
    };
    head.append(heading,button('بستن',function(){modal.close();}));
    return {overlay:overlay,box:box,heading:heading,modal:modal};
  }
  var view=dialog('mh-view','سوابق معدن',function(){if(busy && !switching)return false;generation+=1;return true;});
  var viewMessage=$('<div>').appendTo(view.box), viewActions=$('<div>').addClass('ec-actions').appendTo(view.box);
  var add=button('ثبت سابقه جدید',function(){openEditor(null);},true).appendTo(viewActions);
  var groups=$('<div>').addClass('mh-groups').appendTo(view.box);
  var editor=dialog('mh-editor','ثبت سابقه جدید',function(){
    if(busy || (queue && queue.isRunning())) return false;
    if(!switching && (snapshot()!==baseline || (queue && queue.remaining()>0)) && !global.confirm(saved?'برخی پیوست‌ها بارگذاری نشده‌اند. سابقه ذخیره شده؛ از فرم خارج می‌شوید؟':'تغییرات ذخیره نشده‌اند. از فرم خارج می‌شوید؟')) return false;
    calendar.close(); if(!switching) setTimeout(function(){showViewer(activeCategory);},0);return true;
  });
  editor.box.addClass('mh-editor');
  var editorMessage=$('<div>').appendTo(editor.box), mineLabel=$('<p>').appendTo(editor.box);
  var grid=$('<div>').addClass('ec-grid').appendTo(editor.box);
  function field(label, input, wide) {
    var cell=$('<div>').addClass(wide?'ec-wide':'').appendTo(grid);
    cell.append($('<label>').attr('for',input.attr('id')).text(label),input);return input;
  }
  var search=field('جست‌وجوی دسته‌بندی',$('<input>').attr({id:'mh-category-search',type:'search',placeholder:'بخشی از عنوان دسته را بنویسید'}));
  var category=field('دسته‌بندی *',$('<select>').attr({id:'mh-category','aria-required':'true'}).addClass('mh-category-select'));
  var date=field('تاریخ اجرا *',$('<input>').attr({id:'mh-date',type:'text',inputmode:'numeric',maxlength:10,placeholder:'۱۴۰۵/۰۱/۰۱','aria-required':'true'}));
  var calendar=new UI.DatePicker(date[0],{today:function(){return UI.todayFromGregorian(settings.today);}});
  var description=field('شرح *',$('<textarea>').attr({id:'mh-description',maxlength:5000,'aria-required':'true'}),true);
  var files=field('پیوست‌ها',$('<input>').attr({id:'mh-files',type:'file',multiple:true,accept:'.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png'}),true);
  var fileHelp=$('<small>').addClass('ec-wide').appendTo(grid);
  var existingFiles=$('<div>').addClass('mh-file-list').appendTo(editor.box);
  var progress=$('<div>').attr({'aria-live':'polite',id:'mh-progress'}).appendTo(editor.box);
  var foot=$('<div>').addClass('ec-dialog-foot').appendTo(editor.box);
  var save=button('ذخیره',saveEditor,true).appendTo(foot);
  button('بازگشت به سوابق',function(){editor.modal.close();}).appendTo(foot);
  function snapshot() { return JSON.stringify([category.val(),UI.latinDigits(date.val()),description.val()]); }
  function editableState() {
    if(!settings)return;
    category.add(search).add(date).add(description).prop('disabled',saved || busy);
    files.prop('disabled',busy || saved || !allowed('create') || !settings.storage_ready);
    save.prop('disabled',busy).text(saved?'تلاش مجدد پیوست‌های باقی‌مانده':'ذخیره');
    existingFiles.find('button').prop('disabled',busy);
  }
  function categoryOptions(selected) {
    var q=normalize(search.val()), previous=selected || category.val();category.empty().append($('<option>').val('').text('انتخاب دسته‌بندی'));
    $.each(settings.categories,function(_,c){
      var keep=editing && String(c.id)===String(editing.category_id);
      if((Number(c.is_active)===1 || keep) && (!q || normalize(c.title).indexOf(q)>=0 || String(c.id)===String(previous)))
        category.append($('<option>').val(c.id).text(c.title+(Number(c.is_active)?'':' (غیرفعال)')));
    }); category.val(previous || '');
  }
  function normalize(s) { return String(s||'').replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s\u200c\u200e\u200f]+/g,'').toLowerCase(); }
  search.on('input',function(){categoryOptions();});
  function download(file, historyId, host) {
    var data={action:'download',mine_id:mine.id,id:historyId,file_id:file.id};
    var b=button(file.original_filename+' ('+UI.fileSize(file.file_size)+')',function(){
      b.prop('disabled',true);var capturedMine=mine.id;
      var xhr=new XMLHttpRequest();xhr.open('POST',api);xhr.responseType='blob';
      xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
      xhr.onload=function(){b.prop('disabled',false);if(xhr.status!==200){if(mine && mine.id===capturedMine)global.alert('دریافت پیوست انجام نشد؛ دسترسی یا نشست را بررسی کنید.');return;}
        var url=URL.createObjectURL(xhr.response),a=document.createElement('a');a.href=url;a.download=file.original_filename;document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},1000);};
      xhr.onerror=function(){b.prop('disabled',false);global.alert('ارتباط برای دریافت فایل برقرار نشد.');};xhr.send($.param(data));
    });host.append(b);return b;
  }
  function renderFiles() {
    existingFiles.empty();if(!editing)return;
    $.each(editing.files||[],function(_,f){
      var row=$('<div>').addClass('ec-file').appendTo(existingFiles);download(f,editing.id,row);
      if(allowed('delete')) row.append(button('حذف پیوست',function(){
        if(busy || !global.confirm('این پیوست حذف شود؟'))return;busy=true;editableState();
        request('delete_file',{mine_id:mine.id,id:editing.id,file_id:f.id,lock_version:editing.lock_version})
          .then(function(){return request('get',{mine_id:mine.id,id:editing.id});})
          .done(function(r){editing=r.data;renderFiles();message(editorMessage,'پیوست حذف شد.');})
          .fail(function(x){message(editorMessage,error(x),true);})
          .always(function(){busy=false;editableState();});
      }));
    });
  }
  function resetQueue() {
    queue=new UI.UploadQueue({
      send:function(file,requestId,onProgress){
        var data=new FormData();$.each({action:'upload',mine_id:mine.id,id:editing.id,lock_version:editing.lock_version,request_id:requestId},function(k,v){data.append(k,v);});data.append('file',file);
        return $.ajax({url:api,type:'POST',data:data,dataType:'json',contentType:false,processData:false,headers:{'X-CSRF-Token':token},xhr:function(){var x=$.ajaxSettings.xhr();if(x.upload)x.upload.addEventListener('progress',onProgress);return x;}});
      },
      onProgress:function(percent,name,index,total){
        progress.empty().append($('<p>').text('پیوست '+UI.persianDigits(index+1)+' از '+UI.persianDigits(total)+': '+name),$('<progress>').attr({max:100,value:percent,'aria-label':'پیشرفت بارگذاری'}));
      },
      onSuccess:function(r){editing.lock_version=r.lock_version;if(!editing.files.some(function(f){return String(f.id)===String(r.data.id);}))editing.files.push(r.data);renderFiles();},
      onError:function(x,name){busy=false;message(editorMessage,'سابقه ذخیره شده است. بارگذاری «'+name+'» انجام نشد: '+error(x),true);editableState();},
      onFinish:function(){busy=false;baseline=snapshot();files.val('');switching=true;editor.modal.close();switching=false;showViewer(editing.category_id);}
    });
  }
  files.on('change',function(){
    var selected=Array.prototype.slice.call(this.files||[]), count=editing?(editing.files||[]).length:0;
    if(selected.length+count>10){message(editorMessage,'مجموع پیوست‌های هر سابقه حداکثر ۱۰ فایل است.',true);this.value='';queue.prepare([]);return;}
    var invalid=selected.some(function(f){var ext=f.name.split('.').pop().toLowerCase();return f.size<=0 || f.size>settings.max_upload_bytes || settings.extensions.indexOf(ext)<0;});
    if(invalid){message(editorMessage,'نوع یا اندازهٔ یکی از فایل‌ها مجاز نیست.',true);this.value='';queue.prepare([]);return;}
    message(editorMessage,'');progress.empty();$.each(selected,function(_,f){progress.append($('<p>').addClass('mh-progress-row').text(f.name+'، '+UI.fileSize(f.size)+'، در انتظار بارگذاری'));});queue.prepare(selected);
  });
  function openEditor(id) {
    if(busy)return;var mineId=mine.id;busy=true;
    $.when(lookups(),id?request('get',{mine_id:mineId,id:id}).then(function(r){return r;}):$.Deferred().resolve({data:null}).promise())
      .done(function(_,result){
        editing=result.data || null;createRequest=UI.requestId();saved=false;resetQueue();search.val('');
        categoryOptions(editing?editing.category_id:activeCategory);date.val(editing?UI.persianDigits(editing.executed_on_fa):UI.todayFromGregorian(settings.today));
        description.val(editing?editing.description:'');files.val('');progress.empty();message(editorMessage,'');
        mineLabel.text('معدن: '+mine.mine_name);editor.heading.text(editing?'ویرایش سابقه':'ثبت سابقه جدید');
        fileHelp.text('حداکثر ۱۰ پیوست؛ هر فایل تا '+UI.fileSize(settings.max_upload_bytes)+'. PDF، Word، Excel و تصویر.'+(!settings.storage_ready?' مخزن پیوست در دسترس نیست؛ ثبت بدون فایل امکان‌پذیر است.':''));
        renderFiles();baseline=snapshot();switching=true;view.modal.close();switching=false;editor.modal.open(trigger);
      }).fail(function(x){message(viewMessage,error(x),true);}).always(function(){busy=false;editableState();});
  }
  function saveEditor() {
    if(busy)return;
    if(saved){busy=true;editableState();queue.start();return;}
    var parsed=UI.parseDate(date.val());
    if(!category.val() || !parsed || !$.trim(description.val())){message(editorMessage,'دسته‌بندی، تاریخ معتبر و شرح را وارد کنید.',true);return;}
    var g=UI.jalaali.toGregorian(parsed.jy,parsed.jm,parsed.jd),iso=g.gy+'-'+('0'+g.gm).slice(-2)+'-'+('0'+g.gd).slice(-2);
    if(iso>settings.today){message(editorMessage,'تاریخ اجرا نمی‌تواند در آینده باشد.',true);return;}
    busy=true;editableState();message(editorMessage,'در حال ذخیره…');
    request(editing?'update':'create',{mine_id:mine.id,id:editing?editing.id:'',lock_version:editing?editing.lock_version:'',request_id:createRequest,category_id:category.val(),executed_on_fa:UI.latinDigits(date.val()),description:description.val()})
      .done(function(r){editing=r.data;saved=true;baseline=snapshot();activeCategory=editing.category_id;editableState();queue.start();
        if(!queue.remaining()){busy=false;switching=true;editor.modal.close();switching=false;showViewer(activeCategory);}
      }).fail(function(x){busy=false;message(editorMessage,error(x),true);editableState();});
  }
  function renderEntry(record,host) {
    var entry=$('<article>').addClass('mh-entry').appendTo(host),meta=$('<div>').addClass('mh-meta').appendTo(entry);
    function metadata(label,value,tag){meta.append($('<div>').addClass('mh-meta-item').append($('<span>').addClass('mh-meta-label').text(label),$('<'+(tag||'span')+'>').addClass('mh-meta-value').text(value)));}
    metadata('تاریخ اجرا',UI.persianDigits(record.executed_on_fa),'strong');metadata('ثبت‌کننده',record.creator_name);
    metadata('زمان ثبت',registeredAt(record.created_at));
    if(record.edited_at)meta.append($('<span>').addClass('ec-badge').text('ویرایش‌شده'));
    var body=$('<div>').addClass('mh-entry-body').toggleClass('mh-has-files',record.files.length>0).appendTo(entry);
    var content=$('<div>').addClass('mh-entry-content').appendTo(body);
    content.append($('<p>').addClass('mh-description').text(record.description));
    var attachments=$('<aside>').addClass('mh-attachments').attr('aria-label','پیوست‌های سابقه');
    if(record.files.length)body.append(attachments.append($('<h3>').text('پیوست‌ها ('+UI.persianDigits(record.files.length)+')')));
    var attachmentList=$('<div>').addClass('mh-attachment-list').attr({role:'region','aria-label':'فهرست پیوست‌های سابقه',tabindex:'0'}).appendTo(attachments);
    attachments.append($('<small>').addClass('mh-attachment-hint').prop('hidden',true).text('برای دیدن فایل‌های بیشتر، فهرست را اسکرول کنید.'));
    $.each(record.files,function(_,f){
      var line=$('<div>').addClass('ec-file mh-attachment').appendTo(attachmentList);
      download(f,record.id,line).addClass('mh-download').attr({'aria-label':'دانلود '+f.original_filename,title:f.original_filename}).empty()
        .append(icon('file'),$('<span>').addClass('mh-file-info').append($('<bdi>').attr('dir','auto').addClass('mh-filename').text(f.original_filename),$('<small>').text(UI.fileSize(f.file_size))));
      if(allowed('delete'))line.append(iconButton('حذف پیوست','delete',function(){
        if(busy || !global.confirm('این پیوست حذف شود؟'))return;busy=true;
        request('delete_file',{mine_id:mine.id,id:record.id,file_id:f.id,lock_version:record.lock_version})
          .done(function(){showViewer(record.category_id);}).fail(function(x){message(viewMessage,error(x),true);}).always(function(){busy=false;});
      }));
    });
    var actions=$('<div>').addClass('ec-actions mh-entry-actions').attr('aria-label','عملیات سابقه').appendTo(meta);
    if(allowed('update'))actions.append(iconButton('ویرایش','edit',function(){openEditor(record.id);}));
    if(allowed('delete'))actions.append(iconButton('حذف','delete',function(){
      if(busy || !global.confirm('این سابقه و دسترسی به پیوست‌های آن حذف شود؟'))return;busy=true;
      request('delete',{mine_id:mine.id,id:record.id,lock_version:record.lock_version}).done(function(){showViewer(record.category_id);})
        .fail(function(x){message(viewMessage,error(x),true);}).always(function(){busy=false;});
    }));
    global.requestAnimationFrame(updateAttachmentHints);
  }
  function showViewer(preferred) {
    activeCategory=preferred || activeCategory;
    if(!view.modal.isOpen())view.modal.open(trigger);
    var epoch=++generation;view.heading.text('سوابق معدن '+mine.mine_name);groups.empty();message(viewMessage,'در حال بارگذاری…');
    add.toggle(allowed('create'));
    request('summary',{mine_id:mine.id}).done(function(r){
      if(epoch!==generation || !view.modal.isOpen())return;
      mine.mine_name=r.mine.mine_name;view.heading.text('سوابق معدن '+mine.mine_name);message(viewMessage,r.data.length?'':'هنوز سابقه‌ای برای این معدن ثبت نشده است.');
      var found=r.data.some(function(c){return String(c.id)===String(activeCategory);});
      $.each(r.data,function(i,c){
        var details=$('<details>').appendTo(groups),summary=$('<summary>').attr('tabindex','0').text(c.title+' ('+UI.persianDigits(c.record_count)+')'+(Number(c.is_active)?'':'، غیرفعال')).appendTo(details);
        var body=$('<div>').addClass('mh-group-body').appendTo(details),rows=$('<div>').appendTo(body),status=$('<div>').appendTo(body);
        var offset=0,loaded=false,loading=false;
        var more=button('نمایش بیشتر',load).appendTo(body).hide();
        function load(){
          if(loading)return;loading=true;more.prop('disabled',true);message(status,'در حال بارگذاری…');
          request('list',{mine_id:mine.id,category_id:c.id,offset:offset}).done(function(result){
            if(epoch!==generation)return;loaded=true;offset=result.next_offset;message(status,'');
            $.each(result.data,function(_,record){renderEntry(record,rows);});more.toggle(result.has_more);
          }).fail(function(x){if(epoch===generation){message(status,error(x),true);more.show();}}).always(function(){loading=false;more.prop('disabled',false);});
        }
        details.on('toggle',function(){if(this.open){activeCategory=c.id;if(!loaded)load();global.requestAnimationFrame(updateAttachmentHints);}});
        if(found?String(c.id)===String(activeCategory):i===0)details.prop('open',true);
      });
    }).fail(function(x){if(epoch===generation)message(viewMessage,error(x),true);});
  }

  var manager=dialog('mh-manager','دسته‌بندی‌های پیگیری',function(){if(busy)return false;if(categoryDirty() && !global.confirm('تغییرات دسته‌بندی ذخیره نشده‌اند. خارج می‌شوید؟'))return false;return true;});
  var managerMessage=$('<div>').appendTo(manager.box),categoryRows=$('<div>').appendTo(manager.box),categoryBaseline='';
  function categorySnapshot(){return JSON.stringify(categoryRows.find('input').map(function(){return this.type==='checkbox'?this.checked:$(this).val();}).get());}
  function categoryDirty(){return categorySnapshot()!==categoryBaseline;}
  function managerRows(){
    categoryRows.empty();var categories=settings.categories.slice();if(allowed('create'))categories.push({id:null,title:'',sort_order:0,is_active:1});
    $.each(categories,function(_,c){
      var row=$('<div>').addClass('mh-category-row').appendTo(categoryRows),title=$('<input>').attr({type:'text',maxlength:200,'aria-label':c.id?'عنوان دسته':'عنوان دستهٔ جدید'}).val(c.title);
      var order=$('<input>').attr({type:'text',inputmode:'numeric','aria-label':'ترتیب نمایش'}).val(c.sort_order);
      var active=$('<input>').attr({type:'checkbox','aria-label':'فعال'}).prop('checked',Number(c.is_active)===1);
      row.append($('<label>').text(c.id?'عنوان دسته':'دستهٔ جدید').append(title),$('<label>').text('ترتیب').append(order),$('<label>').text('فعال').append(active));
      var can=c.id?allowed('update'):allowed('create');title.add(order).add(active).prop('disabled',!can);
      row.data('baseline',JSON.stringify([title.val(),order.val(),active.prop('checked')]));
      if(can)row.append(button(c.id?'ذخیره':'افزودن',function(){
        if(busy)return;if(!$.trim(title.val())){message(managerMessage,'عنوان دسته الزامی است.',true);return;}
        var otherDirty=false;categoryRows.children().not(row).each(function(){var values=$(this).find('input').map(function(){return this.type==='checkbox'?this.checked:$(this).val();}).get();if(JSON.stringify(values)!==$(this).data('baseline'))otherDirty=true;});
        if(otherDirty && !global.confirm('با ذخیرهٔ این ردیف، تغییرات ذخیره‌نشدهٔ ردیف‌های دیگر کنار گذاشته می‌شوند. ادامه می‌دهید؟'))return;
        busy=true;categoryRows.find('button').prop('disabled',true);
        request(c.id?'category_update':'category_create',{id:c.id,lock_version:c.lock_version,title:title.val(),sort_order:UI.latinDigits(order.val()),is_active:active.prop('checked')?1:0})
          .then(function(r){if(c.id){var index=settings.categories.findIndex(function(x){return String(x.id)===String(c.id);});settings.categories[index]=r.data;}else settings.categories.push(r.data);
            if(c.id){c=r.data;row.data('saved',true);}else{c=r.data;title.val('');order.val('0');active.prop('checked',true);}
            return lookups();})
          .done(function(){managerRows();message(managerMessage,'دسته‌بندی ذخیره شد.');})
          .fail(function(x){message(managerMessage,error(x),true);})
          .always(function(){busy=false;categoryRows.find('button').prop('disabled',false);});
      }));
    });categoryBaseline=categorySnapshot();
  }
  // No nested forms: these controls are embedded inside a ProcessMaker case form.
  root.on('keydown','input',function(e){if(e.key==='Enter'){e.preventDefault();}});
  var toolbar=$('<button>').attr({type:'button',id:'mh-category-toolbar'}).addClass('btn').text('دسته‌بندی‌های پیگیری').hide().appendTo('#toolbar');
  toolbar.on('click',function(){if(busy)return;busy=true;lookups().done(function(){managerRows();message(managerMessage,'');manager.modal.open(toolbar[0]);}).fail(function(x){global.alert(error(x));}).always(function(){busy=false;});});
  $(document).on('click','.mh-history-trigger',function(){
    if(busy)return;var element=this,id=Number($(this).attr('data-mine-id'));busy=true;
    lookups().done(function(){mine={id:id,mine_name:''};trigger=element;activeCategory=null;showViewer();}).fail(function(x){global.alert(error(x));}).always(function(){busy=false;});
  });
  lookups().done(function(){toolbar.toggle(allowed('read'));}).fail(function(){toolbar.hide();});
  global.EmcoreMineHistory={version:'1.0.0'};
}(window,window.jQuery,window.EmcoreUI));
