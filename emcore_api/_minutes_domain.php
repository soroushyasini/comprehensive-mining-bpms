<?php

require_once __DIR__ . '/_module_permissions.php';

const EMCORE_MINUTES_MODULE = 'meeting_minutes';
const EMCORE_MINUTES_DOMAIN_REVISION = '2026-10-04.2';

function emcore_minutes_digits($value)
{
    return strtr((string)$value, array_combine(
        preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
        str_split('01234567890123456789')
    ));
}

function emcore_minutes_text($value, $max, $required = false)
{
    if ($value !== null && !is_scalar($value)) throw new EmcoreHttpException(422, 'مقدار متنی نامعتبر است');
    $value = trim((string)$value);
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value)
        || mb_strlen($value, 'UTF-8') > $max || ($required && $value === '')) {
        throw new EmcoreHttpException(422, 'اطلاعات متنی ناقص یا نامعتبر است');
    }
    return $value === '' ? null : $value;
}

function emcore_minutes_number_key($number)
{
    $number = emcore_minutes_digits(emcore_minutes_text($number, 100, true));
    $number = strtr($number, ['ي' => 'ی', 'ك' => 'ک']);
    $key = mb_strtoupper(preg_replace('/[\s\x{200C}\x{200E}\x{200F}]+/u', '', $number), 'UTF-8');
    if ($key === '') throw new EmcoreHttpException(422, 'شماره صورت‌جلسه نمی‌تواند خالی باشد');
    return $key;
}

function emcore_minutes_int($name, $default = null, $max = 2147483647)
{
    if (isset($_POST[$name]) && !is_scalar($_POST[$name])) throw new EmcoreHttpException(422, 'شناسه نامعتبر است');
    $raw = isset($_POST[$name]) ? emcore_minutes_digits($_POST[$name]) : '';
    if ($raw === '' && $default !== null) return $default;
    if (!preg_match('/^[1-9][0-9]*$/', $raw) || strlen($raw) > 10 || (int)$raw > $max) {
        throw new EmcoreHttpException(422, 'عدد یا شناسه نامعتبر است', [$name => 'positive_integer_required']);
    }
    return (int)$raw;
}

function emcore_minutes_request_id()
{
    $id = emcore_minutes_text($_POST['request_id'] ?? null, 32, true);
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new EmcoreHttpException(422, 'شناسه درخواست نامعتبر است');
    return $id;
}

function emcore_minutes_bool($name)
{
    if (isset($_POST[$name]) && !is_scalar($_POST[$name])) throw new EmcoreHttpException(422, 'مقدار بله/خیر نامعتبر است');
    return emcore_post_bool($name);
}

function emcore_minutes_enum($name, $allowed, $default = null)
{
    $value = emcore_minutes_text($_POST[$name] ?? $default, 100);
    if (!in_array($value, $allowed, true)) throw new EmcoreHttpException(422, 'گزینه نامعتبر است', [$name => 'invalid_option']);
    return $value;
}

// The same break algorithm used in the procurement panel. Validate the date
// before converting in PHP; archive operations need no SQL calendar routine.
function emcore_minutes_jalali_cal($jy)
{
    $breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    $jp = $breaks[0]; $leapJ = -14; $jump = 0; $gy = $jy + 621;
    foreach (array_slice($breaks, 1) as $jm) {
        $jump = $jm - $jp;
        if ($jy < $jm) break;
        $leapJ += intdiv($jump, 33) * 8 + intdiv($jump % 33, 4); $jp = $jm;
    }
    $n = $jy - $jp;
    $leapJ += intdiv($n,33)*8 + intdiv(($n % 33)+3,4);
    if (($jump % 33) === 4 && $jump - $n === 4) $leapJ++;
    $march = 20 + $leapJ - (intdiv($gy,4) - intdiv((intdiv($gy,100)+1)*3,4) - 150);
    if ($jump - $n < 6) $n = $n - $jump + intdiv($jump+4,33)*33;
    $leap = (($n+1) % 33 - 1) % 4;
    if ($leap === -1) $leap = 4;
    return ['gy' => $gy, 'march' => $march, 'leap' => $leap];
}

function emcore_minutes_date($db, $value)
{
    $value = emcore_minutes_text($value, 10);
    if ($value === null) return [null, null];
    $value = emcore_minutes_digits($value);
    if (!preg_match('/^(1[34][0-9]{2})\/(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])$/', $value, $m)) {
        throw new EmcoreHttpException(422, 'تاریخ شمسی باید با قالب YYYY/MM/DD باشد');
    }
    $y = (int)$m[1]; $month = (int)$m[2]; $day = (int)$m[3]; $cal = emcore_minutes_jalali_cal($y);
    $max = $month <= 6 ? 31 : ($month <= 11 ? 30 : ($cal['leap'] === 0 ? 30 : 29));
    if ($day > $max) throw new EmcoreHttpException(422, 'روز انتخاب‌شده در تقویم شمسی معتبر نیست');
    $expected = (new DateTimeImmutable(sprintf('%04d-03-%02d', $cal['gy'], $cal['march']), new DateTimeZone('Asia/Tehran')))
        ->modify('+' . (($month-1)*31 - intdiv($month,7)*($month-7) + $day-1) . ' days')->format('Y-m-d');
    return [$value, $expected];
}

function emcore_minutes_time($value)
{
    $value = emcore_minutes_text($value, 5);
    if ($value === null) return null;
    $value = emcore_minutes_digits($value);
    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) throw new EmcoreHttpException(422, 'ساعت باید با قالب HH:MM باشد');
    return $value;
}

function emcore_minutes_participants($db, $raw, $existing = [])
{
    if (!is_string($raw) || strlen($raw) > 200000) throw new EmcoreHttpException(422, 'فهرست افراد نامعتبر است');
    $items = json_decode($raw, true);
    if (!is_array($items) || array_values($items) !== $items || count($items) > 300) {
        throw new EmcoreHttpException(422, 'فهرست افراد باید آرایه باشد؛ حداکثر ۳۰۰ نفر در هر جلسه');
    }
    $old = []; foreach ($existing as $p) $old[$p['person_key']] = $p;
    $result = []; $chair = 0; $secretary = 0;
    foreach ($items as $p) {
        if (!is_array($p)) throw new EmcoreHttpException(422, 'شخص نامعتبر است');
        $uid = emcore_minutes_text($p['usr_uid'] ?? null, 32);
        $organization = emcore_minutes_text($p['organization_snapshot'] ?? null, 200);
        if ($uid !== null) {
            if (!preg_match('/^[A-Za-z0-9]{32}$/', $uid)) throw new EmcoreHttpException(422, 'شناسه شخص نامعتبر است');
            $key = 'u:' . $uid;
            if (isset($old[$key])) {
                $name = $old[$key]['name_snapshot']; $organization = $old[$key]['organization_snapshot'];
            } else {
                $stmt = $db->prepare("SELECT USR_FIRSTNAME,USR_LASTNAME,USR_USERNAME FROM USERS WHERE USR_UID=:uid AND USR_STATUS='ACTIVE'");
                $stmt->execute([':uid'=>$uid]); $u = $stmt->fetch();
                if (!$u) throw new EmcoreHttpException(422, 'کاربر انتخاب‌شده فعال نیست');
                $name = trim($u['USR_FIRSTNAME'].' '.$u['USR_LASTNAME']) ?: $u['USR_USERNAME'];
                $name = emcore_minutes_text($name, 200, true); $organization = null;
            }
        } else {
            $name = emcore_minutes_text($p['name_snapshot'] ?? null, 200, true);
            $identity = strtr(mb_strtolower($name.'|'.(string)$organization, 'UTF-8'), ['ي'=>'ی','ك'=>'ک']);
            $key = 'e:' . hash('sha256', preg_replace('/\s+/u', ' ', $identity));
        }
        $attendance = $p['attendance'] ?? '';
        if (!in_array($attendance, ['present','absent'], true)) throw new EmcoreHttpException(422, 'وضعیت حضور نامعتبر است');
        foreach (['is_chair','is_secretary'] as $flag) {
            if (isset($p[$flag]) && !in_array($p[$flag], [true,false,0,1,'0','1'], true)) throw new EmcoreHttpException(422, 'سمت جلسه نامعتبر است');
        }
        $isChair = empty($p['is_chair']) ? 0 : 1; $isSecretary = empty($p['is_secretary']) ? 0 : 1;
        if (($isChair || $isSecretary) && $attendance !== 'present') throw new EmcoreHttpException(422, 'رئیس و دبیر باید در حاضرین باشند');
        if (isset($result[$key])) throw new EmcoreHttpException(422, 'یک شخص بیش از یک بار انتخاب شده است');
        $chair += $isChair; $secretary += $isSecretary;
        $result[$key] = ['person_key'=>$key,'usr_uid'=>$uid,'name_snapshot'=>$name,'organization_snapshot'=>$organization,
            'attendance'=>$attendance,'is_chair'=>$isChair,'is_secretary'=>$isSecretary];
    }
    if ($chair > 1 || $secretary > 1) throw new EmcoreHttpException(422, 'فقط یک رئیس و یک دبیر برای جلسه مجاز است');
    ksort($result); return array_values($result);
}

function emcore_minutes_input($db, $origin, $existing = [])
{
    list($fa, $en) = emcore_minutes_date($db, $_POST['meeting_date_fa'] ?? null);
    $start = emcore_minutes_time($_POST['start_time'] ?? null); $end = emcore_minutes_time($_POST['end_time'] ?? null);
    $next = emcore_minutes_bool('ends_next_day');
    if ($start !== null && $end !== null && !$next && $end <= $start) throw new EmcoreHttpException(422, 'ساعت خاتمه باید بعد از شروع باشد؛ عبور از نیمه‌شب را مشخص کنید');
    if ($next && ($start === null || $end === null)) throw new EmcoreHttpException(422, 'برای خاتمه در روز بعد هر دو ساعت لازم است');
    $participants = emcore_minutes_participants($db, $_POST['participants'] ?? '[]', $existing);
    $agenda = emcore_minutes_text($_POST['agenda'] ?? null, 20000);
    $complete = $fa !== null && $agenda !== null
        && count(array_filter($participants, function($p) { return $p['is_chair']; })) === 1
        && count(array_filter($participants, function($p) { return $p['is_secretary']; })) === 1;
    if ($origin === 'managed' && !$complete) throw new EmcoreHttpException(422, 'تاریخ، رئیس، دبیر و دستور جلسه الزامی‌اند');
    return ['title'=>emcore_minutes_text($_POST['title'] ?? null,500,true),'meeting_date_fa'=>$fa,'meeting_date_en'=>$en,
        'start_time'=>$start,'end_time'=>$end,'ends_next_day'=>$next,'agenda'=>$agenda,
        'notes'=>emcore_minutes_text($_POST['notes'] ?? null,20000),'metadata_complete'=>$complete ? 1 : 0,'participants'=>$participants];
}

function emcore_minutes_hash($value) { return hash('sha256', emcore_audit_json($value)); }

function emcore_minutes_number($code, $year, $sequence)
{
    return $code . '/' . $year . '/' . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
}

function emcore_minutes_available_number($db, $company, $code, $year, $sequence)
{
    // Legacy entries may already occupy the new spelling. Never renumber them.
    // The create caller holds the company/counter locks; preview is provisional.
    $lookup = $db->prepare('SELECT id FROM emcore_meeting_minutes WHERE company_id=:company AND number_key=:number LIMIT 1');
    while (true) {
        if ((float)$sequence >= PHP_INT_MAX) throw new EmcoreHttpException(409, 'ظرفیت شمارنده به پایان رسیده است');
        $number = emcore_minutes_number($code, $year, $sequence);
        $lookup->execute([':company'=>$company, ':number'=>$number]);
        if (!$lookup->fetchColumn()) return ['number'=>$number, 'sequence'=>$sequence];
        $sequence = (int)$sequence + 1;
    }
}
