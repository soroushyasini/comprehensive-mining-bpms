/* EMCORE UI v1. Same-origin, jQuery 1.11 compatible, no global AJAX hooks. */
(function (global, $) {
  'use strict';
  if (!$) throw new Error('EMCORE UI requires the ProcessMaker jQuery runtime.');
  var serial = 0;
  function latin(value) { return String(value == null ? '' : value).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }); }
  function persian(value) { return String(value == null ? '' : value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; }); }
  function two(value) { return String(value).length < 2 ? '0' + value : String(value); }
  function requestId() {
    if (!global.crypto || !global.crypto.getRandomValues) throw new Error('مرورگر از تولید شناسه امن پشتیبانی نمی‌کند.');
    var bytes = new Uint8Array(16); global.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (v) { return ('0' + v.toString(16)).slice(-2); }).join('');
  }
  function errorText(xhr) {
    if (xhr && xhr.responseJSON && xhr.responseJSON.error) return xhr.responseJSON.error;
    if (xhr && xhr.status === 401) return 'نشست شما پایان یافته است؛ دوباره وارد سامانه شوید.';
    return 'ارتباط با سامانه ناموفق بود؛ اتصال را بررسی و دوباره تلاش کنید.';
  }
  function size(bytes) { return bytes >= 1048576 ? persian((bytes / 1048576).toFixed(1)) + ' مگابایت' : persian((bytes / 1024).toFixed(1)) + ' کیلوبایت'; }
  var jalaali = (function () {
    var breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    function div(a, b) { return ~~(a / b); }
    function mod(a, b) { return a - ~~(a / b) * b; }
    function jalCal(jy, withoutLeap) {
      var bl = breaks.length; var gy = jy + 621; var leapJ = -14; var jp = breaks[0];
      var jm; var jump; var leap; var leapG; var march; var n; var i;
      if (jy < jp || jy >= breaks[bl - 1]) throw new Error('سال شمسی خارج از محدوده است.');
      for (i = 1; i < bl; i += 1) { jm = breaks[i]; jump = jm - jp; if (jy < jm) break; leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4); jp = jm; }
      n = jy - jp;
      leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
      if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
      leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
      march = 20 + leapJ - leapG;
      if (withoutLeap) return { gy: gy, march: march };
      if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
      leap = mod(mod(n + 1, 33) - 1, 4);
      if (leap === -1) leap = 4;
      return { leap: leap, gy: gy, march: march };
    }
    function g2d(gy, gm, gd) {
      var value = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
      return value - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
    }
    function d2g(jdn) {
      var j = 4 * jdn + 139361631;
      j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
      var i = div(mod(j, 1461), 4) * 5 + 308;
      var gd = div(mod(i, 153), 5) + 1;
      var gm = mod(div(i, 153), 12) + 1;
      var gy = div(j, 1461) - 100100 + div(8 - gm, 6);
      return { gy: gy, gm: gm, gd: gd };
    }
    function j2d(jy, jm, jd) {
      var result = jalCal(jy, true);
      return g2d(result.gy, 3, result.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
    }
    function d2j(jdn) {
      var gregorian = d2g(jdn); var jy = gregorian.gy - 621; var result = jalCal(jy, false);
      var firstFarvardin = g2d(gregorian.gy, 3, result.march); var k = jdn - firstFarvardin; var jm; var jd;
      if (k >= 0) {
        if (k <= 185) return { jy: jy, jm: 1 + div(k, 31), jd: mod(k, 31) + 1 };
        k -= 186;
      } else {
        jy -= 1; k += 179; if (result.leap === 1) k += 1;
      }
      jm = 7 + div(k, 30); jd = mod(k, 30) + 1;
      return { jy: jy, jm: jm, jd: jd };
    }
    function toJalaali(dateOrYear, month, day) {
      var year = dateOrYear;
      if (dateOrYear && typeof dateOrYear.toISOString === 'function') {
        var isoMatch = /^(\d{4})-(\d{2})-(\d{2})/.exec(dateOrYear.toISOString());
        if (!isoMatch) throw new Error('تاریخ میلادی معتبر نیست.');
        year = Number(isoMatch[1]); month = Number(isoMatch[2]); day = Number(isoMatch[3]);
      }
      year = Number(year); month = Number(month); day = Number(day);
      if (!isFinite(year) || !isFinite(month) || !isFinite(day)) throw new Error('تاریخ میلادی معتبر نیست.');
      return d2j(g2d(year, month, day));
    }
    function toGregorian(year, month, day) { return d2g(j2d(Number(year), Number(month), Number(day))); }
    function isLeapJalaaliYear(year) { return jalCal(Number(year), false).leap === 0; }
    function jalaaliMonthLength(year, month) { if (month <= 6) return 31; if (month <= 11) return 30; return isLeapJalaaliYear(year) ? 30 : 29; }
    function isValidJalaaliDate(year, month, day) {
      return year >= -61 && year <= 3177 && month >= 1 && month <= 12 && day >= 1 && day <= jalaaliMonthLength(year, month);
    }
    function toDate(year, month, day) {
      if (!isValidJalaaliDate(year, month, day)) throw new Error('تاریخ شمسی معتبر نیست.');
      var gregorian = toGregorian(year, month, day);
      return new Date(gregorian.gy, gregorian.gm - 1, gregorian.gd, 12, 0, 0, 0);
    }
    function addDays(year, month, day, delta) { return d2j(j2d(Number(year), Number(month), Number(day)) + Number(delta)); }
    function weekDay(year, month, day) { return mod(j2d(Number(year), Number(month), Number(day)) + 1, 7); }
    return { toJalaali: toJalaali, toGregorian: toGregorian, toDate: toDate, addDays: addDays, weekDay: weekDay, isValidJalaaliDate: isValidJalaaliDate, jalaaliMonthLength: jalaaliMonthLength };
  }());
  function formatDate(y, m, d) { return persian(y + '/' + two(m) + '/' + two(d)); }
  function parseDate(value) {
    var match = /^(1[34][0-9]{2})\/(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])$/.exec(latin($.trim(value)));
    if (!match) return null;
    var y = Number(match[1]), m = Number(match[2]), d = Number(match[3]);
    return jalaali.isValidJalaaliDate(y, m, d) ? { jy: y, jm: m, jd: d } : null;
  }
  function todayFromGregorian(iso) {
    var p = String(iso).split('-'); var j = jalaali.toJalaali(Number(p[0]), Number(p[1]), Number(p[2]));
    return formatDate(j.jy, j.jm, j.jd);
  }
  function key(event) { return event.key || (event.originalEvent && event.originalEvent.key) || { 9: 'Tab', 13: 'Enter', 27: 'Escape', 33: 'PageUp', 34: 'PageDown', 35: 'End', 36: 'Home', 37: 'ArrowLeft', 38: 'ArrowUp', 39: 'ArrowRight', 40: 'ArrowDown' }[event.which]; }

  function DatePicker(input, options) {
    var self = this, field = $(input), host = field.parent(), id = 'emcore-calendar-' + (++serial);
    var panel = $('<div>').addClass('ec-calendar').attr({ id: id, role: 'dialog', 'aria-label': 'انتخاب تاریخ شمسی', hidden: true });
    var trigger = $('<button>').addClass('ec-btn ec-date-trigger').attr({ type: 'button', 'aria-label': 'بازکردن تقویم', 'aria-controls': id, 'aria-expanded': 'false' }).text('تقویم');
    var label = $('<strong>').attr('aria-live', 'polite'), days = $('<div>').addClass('ec-days').attr('role', 'grid');
    var head = $('<div>').addClass('ec-calendar-head'), y, m, d;
    options = options || {}; host.addClass('ec-date-field').append(trigger, panel);
    function button(text, fn) { return $('<button>').attr('type', 'button').addClass('ec-btn').text(text).on('click', fn); }
    head.append(button('ماه قبل', function () { moveMonth(-1); }), label, button('ماه بعد', function () { moveMonth(1); }));
    var week = $('<div>').addClass('ec-calendar-week'); $.each(['ش','ی','د','س','چ','پ','ج'], function (_, text) { week.append($('<span>').text(text)); });
    panel.append(head, week, days, $('<div>').addClass('ec-calendar-head').append(button('امروز', function () { var t = parseDate(options.today()); select(t); }), button('بستن', function () { self.close(); })));
    function select(t) { field.val(formatDate(t.jy, t.jm, t.jd)).trigger('change'); self.close(); field.trigger('focus'); }
    function render() {
      label.text(['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'][m-1] + ' ' + persian(y));
      days.empty(); var offset = (jalaali.weekDay(y,m,1)+1)%7, max = jalaali.jalaaliMonthLength(y,m), chosen = parseDate(field.val());
      var today = parseDate(options.today()), cell = 0;
      for (var r = 0; r < Math.ceil((offset + max)/7); r += 1) {
        var row = $('<div>').attr('role', 'row').addClass('ec-calendar-week');
        for (var c = 0; c < 7; c += 1, cell += 1) {
          var n = cell - offset + 1;
          if (n < 1 || n > max) row.append($('<span>').attr({ role:'gridcell', 'aria-hidden':'true' }));
          else {
            var selected = !!(chosen && chosen.jy === y && chosen.jm === m && chosen.jd === n);
            var b = $('<button>').attr({ type:'button', role:'gridcell', 'data-day':n, tabindex:n === d ? 0 : -1, 'aria-label':formatDate(y,m,n), 'aria-selected':String(selected) }).text(persian(n));
            if (today && today.jy === y && today.jm === m && today.jd === n) b.attr('aria-current','date');
            b.on('click', function () { select({ jy:y, jm:m, jd:Number($(this).attr('data-day')) }); }); row.append(b);
          }
        }
        days.append(row);
      }
    }
    function focusDay() { days.find('[data-day="' + d + '"]').trigger('focus'); }
    function moveMonth(delta) { m += delta; if (m < 1) { m=12; y-=1; } if (m > 12) { m=1; y+=1; } d=Math.min(d,jalaali.jalaaliMonthLength(y,m)); render(); focusDay(); }
    self.close = function () { panel.prop('hidden',true); trigger.attr('aria-expanded','false'); };
    trigger.on('click', function () {
      if (field.prop('disabled')) return;
      if (!panel.prop('hidden')) { self.close(); field.trigger('focus'); return; }
      var base=parseDate(field.val()) || parseDate(options.today()); y=base.jy; m=base.jm; d=base.jd;
      panel.prop('hidden',false); trigger.attr('aria-expanded','true'); render(); focusDay();
    });
    panel.on('keydown', function (e) {
      var k=key(e), change={ ArrowLeft:1,ArrowRight:-1,ArrowUp:-7,ArrowDown:7 }[k];
      if (k==='Escape') { e.preventDefault(); e.stopPropagation(); self.close(); field.trigger('focus'); }
      else if (change && $(e.target).is('[data-day]')) { e.preventDefault(); var t=jalaali.addDays(y,m,d,change); y=t.jy; m=t.jm; d=t.jd; render(); focusDay(); }
      else if (k==='PageUp' || k==='PageDown') { e.preventDefault(); moveMonth(k==='PageUp' ? -1 : 1); }
      else if ((k==='Home' || k==='End') && $(e.target).is('[data-day]')) { e.preventDefault(); d=k==='Home' ? 1 : jalaali.jalaaliMonthLength(y,m); render(); focusDay(); }
    });
    $(document).on('mousedown.'+id, function(e) { if (!host[0].contains(e.target)) self.close(); });
    self.destroy=function () { $(document).off('.'+id); trigger.remove(); panel.remove(); };
  }

  function personKey(p) { return p.person_key || (p.usr_uid ? 'u:'+p.usr_uid : 'external:'+String(p.name_snapshot).trim()+'|'+String(p.organization_snapshot || '').trim()); }
  function ParticipantPicker(element, options) {
    var self=this, root=$(element), id='emcore-person-'+(++serial), selected=[], suggestions=[], active=-1, generation=0, timer, pending, disabled=false;
    options=options || {};
    var input=$('<input>').attr({ type:'search',id:id,role:'combobox','aria-label':options.label || 'انتخاب افراد','aria-autocomplete':'list','aria-expanded':'false','aria-controls':id+'-list',autocomplete:'off',placeholder:options.label || 'جست‌وجوی نام' });
    var chips=$('<div>').addClass('ec-chips'), list=$('<div>').attr({ id:id+'-list',role:'listbox',hidden:true }).addClass('ec-suggestions');
    var organization=$('<input>').attr({ type:'text','aria-label':'سازمان فرد بیرونی',placeholder:'سازمان فرد بیرونی، اختیاری',maxlength:200 });
    var add=$('<button>').attr('type','button').addClass('ec-btn').text('افزودن فرد بیرونی');
    var status=$('<small>').attr({'aria-live':'polite',role:'status'});
    root.addClass('ec-picker').append(chips,input,list,status);
    if (options.allowExternal !== false) root.append($('<div>').addClass('ec-external').append(organization,add));
    function close() { list.prop('hidden',true); input.attr('aria-expanded','false').removeAttr('aria-activedescendant'); active=-1; }
    function paint() {
      chips.empty(); $.each(selected,function(index,p) {
        var chip=$('<span>').addClass('ec-chip').append($('<span>').text(p.name_snapshot + (p.organization_snapshot ? '، '+p.organization_snapshot : '')));
        chip.append($('<button>').attr({type:'button','aria-label':'حذف '+p.name_snapshot}).text('×').prop('disabled',disabled).on('click',function(){selected.splice(index,1);paint();if(options.onChange)options.onChange(self.get());})); chips.append(chip);
      });
    }
    function choose(p) {
      if (disabled) return;
      if (!options.multiple) selected=[];
      if (!selected.some(function(x){return personKey(x)===personKey(p);})) selected.push($.extend({},p));
      input.val(''); organization.val(''); close(); paint(); if(options.onChange)options.onChange(self.get()); input.trigger('focus');
    }
    function show(rows) {
      suggestions=rows; list.empty(); active=-1;
      $.each(rows,function(i,p) { list.append($('<div>').attr({id:id+'-option-'+i,role:'option','aria-selected':'false'}).text(p.name_snapshot+(p.username ? ' ('+p.username+')' : '')).on('mousedown',function(e){e.preventDefault();choose(p);})); });
      list.prop('hidden',rows.length===0); input.attr('aria-expanded',String(rows.length>0)); status.text(rows.length ? '' : 'گزینه‌ای یافت نشد.');
    }
    function search() {
      clearTimeout(timer); generation+=1; var current=generation;
      if(pending && pending.abort) pending.abort();
      timer=setTimeout(function(){
        status.text('در حال جست‌وجو…'); pending=options.search(input.val());
        pending.done(function(response){if(current===generation && !disabled && document.activeElement===input[0])show(response.data || []);})
          .fail(function(xhr){if(current===generation && xhr.statusText!=='abort')status.text(errorText(xhr));});
      },180);
    }
    input.on('input focus',function(){if(!disabled)search();}).on('blur',close).on('keydown',function(e){
      var k=key(e);
      if(k==='Escape'){close();e.stopPropagation();e.preventDefault();}
      if((k==='ArrowDown' || k==='ArrowUp') && suggestions.length && !list.prop('hidden')) {
        e.preventDefault(); active=(active+(k==='ArrowDown'?1:-1)+suggestions.length)%suggestions.length;
        list.children().attr('aria-selected','false').eq(active).attr('aria-selected','true'); input.attr('aria-activedescendant',id+'-option-'+active);
      }
      if(k==='Enter' && active>=0 && !list.prop('hidden')){e.preventDefault();choose(suggestions[active]);}
      if(k==='Tab')close();
    });
    add.on('click',function(){var name=$.trim(input.val());if(!name){status.text('نام فرد بیرونی را در کادر جست‌وجو وارد کنید.');input.trigger('focus');return;} choose({usr_uid:null,name_snapshot:name,organization_snapshot:$.trim(organization.val()) || null});});
    $(document).on('mousedown.'+id,function(e){if(!root[0].contains(e.target))close();});
    self.get=function(){return $.map(selected,function(p){return $.extend({},p);});};
    self.set=function(items){generation+=1;clearTimeout(timer);if(pending && pending.abort)pending.abort();selected=$.map(items || [],function(p){return $.extend({},p);});input.val('');status.text('');close();paint();};
    self.setDisabled=function(value){disabled=!!value;root.find('input,button').prop('disabled',disabled);if(disabled){generation+=1;clearTimeout(timer);close();}};
    self.destroy=function(){clearTimeout(timer);if(pending && pending.abort)pending.abort();$(document).off('.'+id);root.empty();};
  }

  function Modal(element, options) {
    var self=this, overlay=$(element), returnFocus, previousOverflow, siblings=[], id='ec-modal-'+(++serial), visible=false;
    options=options || {};
    self.open=function(trigger){
      returnFocus=trigger || document.activeElement;previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';
      overlay.siblings().each(function(){siblings.push({el:this,inert:this.inert});this.inert=true;});
      visible=true;overlay.prop('hidden',false);overlay.find('[role="dialog"]').attr('tabindex','-1').trigger('focus');
      $(document).on('focusin.'+id,function(e){if(visible && !overlay[0].contains(e.target))overlay.find('[role="dialog"]').trigger('focus');});
    };
    self.close=function(){if(options.beforeClose && options.beforeClose()===false)return false;visible=false;overlay.prop('hidden',true);$(document).off('.'+id);document.body.style.overflow=previousOverflow;siblings.forEach(function(s){s.el.inert=s.inert;});siblings=[];if(returnFocus && document.contains(returnFocus))$(returnFocus).trigger('focus');return true;};
    overlay.on('keydown',function(e){
      if(key(e)==='Escape' && !e.isDefaultPrevented()){e.preventDefault();self.close();}
      if(key(e)==='Tab'){
        var fields=overlay.find('button,input,textarea,select,[tabindex="0"]').filter(':visible:not(:disabled)'), first=fields[0], last=fields[fields.length-1];
        if(!fields.length){e.preventDefault();return;}
        if(e.shiftKey && (document.activeElement===first || document.activeElement===overlay.find('[role="dialog"]')[0])){e.preventDefault();$(last).trigger('focus');}
        else if(!e.shiftKey && (document.activeElement===last || document.activeElement===overlay.find('[role="dialog"]')[0])){e.preventDefault();$(first).trigger('focus');}
      }
    });
    self.isOpen=function(){return visible;};
  }

  function UploadQueue(options) {
    var self=this, entries=[], index=0, loaded=0, running=false;
    self.prepare=function(files){if(running)return;entries=Array.prototype.map.call(files,function(file){return{file:file,requestId:requestId()};});index=0;loaded=0;};
    self.remaining=function(){return entries.length-index;}; self.isRunning=function(){return running;};
    function total(){return entries.reduce(function(sum,e){return sum+e.file.size;},0);}
    function next(){
      if(index>=entries.length){running=false;if(options.onFinish)options.onFinish();return;}
      var item=entries[index];
      options.onProgress(total()?loaded*100/total():0,item.file.name,index,entries.length);
      options.send(item.file,item.requestId,function(e){if(e.lengthComputable)options.onProgress(Math.min(100,(loaded+e.loaded)*100/total()),item.file.name,index,entries.length);})
        .done(function(response){loaded+=item.file.size;index+=1;if(options.onSuccess)options.onSuccess(response);next();})
        .fail(function(xhr){running=false;if(options.onError)options.onError(xhr,item.file.name);});
    }
    self.start=function(){if(running || index>=entries.length)return;running=true;next();};
  }

  global.EmcoreUI={version:'1.0.0',latinDigits:latin,persianDigits:persian,requestId:requestId,errorText:errorText,fileSize:size,
    jalaali:jalaali,parseDate:parseDate,formatDate:formatDate,todayFromGregorian:todayFromGregorian,
    DatePicker:DatePicker,ParticipantPicker:ParticipantPicker,personKey:personKey,Modal:Modal,UploadQueue:UploadQueue};
}(window,window.jQuery));
