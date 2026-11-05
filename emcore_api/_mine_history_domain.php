<?php
require_once __DIR__ . '/_module_permissions.php';

function emcore_mh_text($value, $max, $required = false)
{
    if ($value !== null && !is_scalar($value)) throw new EmcoreHttpException(422, 'مقدار متنی نامعتبر است');
    $value = trim((string)$value);
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value)
        || mb_strlen($value, 'UTF-8') > $max || ($required && $value === '')) {
        throw new EmcoreHttpException(422, 'اطلاعات متنی ناقص یا نامعتبر است');
    }
    return $value;
}

function emcore_mh_digits($value)
{
    return strtr((string)$value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
}

function emcore_mh_int($value, $allowZero = false)
{
    $s = emcore_mh_digits(emcore_mh_text($value, 18, true));
    if (!preg_match($allowZero ? '/^(0|[1-9][0-9]*)$/' : '/^[1-9][0-9]*$/', $s) || (float)$s > PHP_INT_MAX) {
        throw new EmcoreHttpException(422, 'عدد یا شناسه نامعتبر است');
    }
    return (int)$s;
}

function emcore_mh_category_key($title)
{
    return mb_strtolower(preg_replace('/[\s\x{200C}\x{200E}\x{200F}]+/u', '', strtr($title, ['ي'=>'ی','ك'=>'ک'])), 'UTF-8');
}

function emcore_mh_request_id()
{
    $id = emcore_mh_text($_POST['request_id'] ?? null, 32, true);
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new EmcoreHttpException(422, 'شناسه درخواست نامعتبر است');
    return $id;
}

// The validated break algorithm used by the shared calendar and minutes module.
function emcore_mh_jalali_cal($jy)
{
    $breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    $jp = $breaks[0]; $leapJ = -14; $gy = $jy + 621; $jump = 0;
    foreach (array_slice($breaks, 1) as $jm) {
        $jump = $jm - $jp;
        if ($jy < $jm) break;
        $leapJ += intdiv($jump,33)*8 + intdiv($jump%33,4); $jp=$jm;
    }
    $n=$jy-$jp; $leapJ+=intdiv($n,33)*8+intdiv(($n%33)+3,4);
    if (($jump%33)===4 && $jump-$n===4) $leapJ++;
    $march=20+$leapJ-(intdiv($gy,4)-intdiv((intdiv($gy,100)+1)*3,4)-150);
    if ($jump-$n<6) $n=$n-$jump+intdiv($jump+4,33)*33;
    $leap=(($n+1)%33-1)%4; if ($leap===-1) $leap=4;
    return ['gy'=>$gy,'march'=>$march,'leap'=>$leap];
}

function emcore_mh_today()
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
}

function emcore_mh_date($value, $today = null)
{
    $value=emcore_mh_digits(emcore_mh_text($value,10,true));
    if (!preg_match('/^(1[34][0-9]{2})\/(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])$/D',$value,$m)) {
        throw new EmcoreHttpException(422,'تاریخ شمسی باید با قالب YYYY/MM/DD باشد');
    }
    $y=(int)$m[1];$month=(int)$m[2];$day=(int)$m[3];$cal=emcore_mh_jalali_cal($y);
    $max=$month<=6?31:($month<=11?30:($cal['leap']===0?30:29));
    if ($day>$max) throw new EmcoreHttpException(422,'روز انتخاب‌شده در تقویم شمسی معتبر نیست');
    $iso=(new DateTimeImmutable(sprintf('%04d-03-%02d',$cal['gy'],$cal['march']),new DateTimeZone('Asia/Tehran')))
        ->modify('+'.(($month-1)*31-intdiv($month,7)*($month-7)+$day-1).' days')->format('Y-m-d');
    if ($iso>($today ?? emcore_mh_today())) throw new EmcoreHttpException(422,'تاریخ اجرای سابقه نمی‌تواند در آینده باشد');
    return [$value,$iso];
}

function emcore_mh_one($sql, $params = [])
{
    $s=emcore_db()->prepare($sql);$s->execute($params);return $s->fetch();
}

function emcore_mh_mine($id, $lock = false)
{
    $row=emcore_mh_one('SELECT id,mine_name FROM emcore_mines WHERE id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$id]);
    if (!$row) throw new EmcoreHttpException(404,'معدن یافت نشد');
    return $row;
}

function emcore_mh_record($id, $mineId, $lock = false)
{
    $row=emcore_mh_one('SELECT * FROM emcore_mine_history WHERE id=? AND mine_id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$id,$mineId]);
    if (!$row) throw new EmcoreHttpException(404,'سابقه یافت نشد');
    return $row;
}

function emcore_mh_version($row)
{
    if (emcore_mh_int($_POST['lock_version'] ?? null)!==(int)$row['lock_version']) {
        throw new EmcoreHttpException(409,'این اطلاعات توسط کاربر دیگری تغییر کرده؛ پنجره را ببندید و دوباره باز کنید');
    }
}

function emcore_mh_public_file($row)
{
    return array_intersect_key($row,array_flip(['id','history_id','original_filename','file_size','mime_type','extension','created_at']));
}

function emcore_mh_files($id)
{
    $s=emcore_db()->prepare('SELECT id,history_id,original_filename,file_size,mime_type,extension,created_at FROM emcore_mine_history_files WHERE history_id=? AND deleted_at IS NULL ORDER BY id');
    $s->execute([$id]);return $s->fetchAll();
}

function emcore_mh_detail($id,$mineId)
{
    $row=emcore_mh_record($id,$mineId);
    $creator=emcore_mh_one('SELECT USR_FIRSTNAME,USR_LASTNAME,USR_USERNAME FROM USERS WHERE USR_UID=?',[$row['created_by_usr_uid']]);
    $row['creator_name']=$creator?(trim($creator['USR_FIRSTNAME'].' '.$creator['USR_LASTNAME'])?:$creator['USR_USERNAME']):'کاربر پیشین';
    unset($row['create_request_id'],$row['create_payload_hash']);
    $row['files']=emcore_mh_files($id);return $row;
}
