<?php
require_once __DIR__.'/_contracts_queries.php';

function ct_export_zip()
{
    if (!class_exists('ZipArchive')) ct_fail(503,'ساخت بستهٔ خروجی در دسترس نیست');
    $path = tempnam(ct_root(),'export-');
    if ($path===false || dirname($path)!==ct_root()) {
        if ($path!==false) unlink($path);
        ct_fail(503,'ساخت فایل موقت در مخزن خصوصی ممکن نیست');
    }
    register_shutdown_function(static function () use ($path) { if (is_file($path)) unlink($path); });
    $zip = new ZipArchive();
    if ($zip->open($path,ZipArchive::OVERWRITE)!==true) ct_fail(503,'ساخت بستهٔ خروجی ناموفق بود');
    return [$zip,$path];
}

function ct_export_case($id)
{
    [$zip,$path] = ct_export_zip();
    $db = emcore_db();
    $db->beginTransaction();
    try {
        $manifest = ct_detail($id);
        $manifest['schema_version']=2;
        $manifest['time_zone']='UTC';
        $manifest['exported_at']=gmdate('c');
        $manifest['documents']=ct_all('SELECT * FROM emcore_contract_documents WHERE contract_id=? ORDER BY id',[$id]);
        $manifest['history']=ct_all('SELECT * FROM emcore_contract_history WHERE contract_id=? AND deleted_at IS NULL ORDER BY id',[$id]);
        $manifest['history']=ct_history_files($manifest['history'],$id);
        $manifest['actions']=ct_all('SELECT * FROM emcore_contract_actions WHERE contract_id=? ORDER BY id',[$id]);
        $manifest['evidence']=ct_all('SELECT * FROM emcore_contract_evidence WHERE contract_id=? ORDER BY id',[$id]);
        foreach ($manifest['documents'] as &$doc) {
            $doc['versions']=[];
            foreach (ct_all('SELECT * FROM emcore_contract_versions WHERE document_id=? AND deleted_at IS NULL ORDER BY version_no',[$doc['id']]) as $version) {
                $basename=preg_replace('~[\\\\/:\\x00-\\x1f]~u','_',$version['original_filename']);
                $version['entry']='documents/'.$doc['id'].'/'.$version['version_no'].'-'.$basename;
                $source = ct_private_path($version['storage_key']);
                if (hash_file('sha256',$source)!==$version['sha256'] || !$zip->addFile($source,$version['entry'])) ct_fail(409,'یک اصل فایل قابل تطبیق نیست');
                if ($version['extracted_text']!==null) {
                    $version['text_entry']='extracted/'.$version['id'].'.md';
                    $zip->addFromString($version['text_entry'],$version['extracted_text']);
                }
                $origin=ct_one('SELECT doc_id,bundle_id,relative_path,sha256,metadata FROM emcore_contract_sources WHERE version_id=?',[$version['id']]);
                $version['origin']=$origin ?: null;
                unset($version['extracted_text'],$version['storage_key']);
                $doc['versions'][]=$version;
            }
        }
        unset($doc);
        if (!$zip->addFromString('manifest.json',ct_json(ct_public($manifest))) || !$zip->close()) ct_fail(503,'ساخت بستهٔ خروجی ناموفق بود');
        ct_log_download($id,null,'case_zip');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    ct_stream($path,$manifest['contract']['code'].'.zip','application/zip',false,true);
}

function ct_xml($value)
{
    return htmlspecialchars(preg_replace('/[^\P{C}\x09\x0A\x0D\x{200C}]/u','',(string)$value),ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function ct_export_list($input)
{
    [$where,$args,$order]=ct_list_scope($input);
    $rows=ct_query("SELECT c.* FROM emcore_contracts c WHERE $where ORDER BY $order",$args);
    [$zip,$path]=ct_export_zip();
    $main='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relationships='http://schemas.openxmlformats.org/package/2006/relationships';
    $office='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $header='<?xml version="1.0" encoding="UTF-8"?>';
    $zip->addFromString('[Content_Types].xml',$header.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
        <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>
        <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
        <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels',$header.'<Relationships xmlns="'.$relationships.'"><Relationship Id="rId1" Type="'.$office.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',$header.'<Relationships xmlns="'.$relationships.'"><Relationship Id="rId1" Type="'.$office.'/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml',$header.'<workbook xmlns="'.$main.'" xmlns:r="'.$office.'"><sheets><sheet name="قراردادها" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $xml=$header.'<worksheet xmlns="'.$main.'"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetData>';
    $writeRow=static function ($cells,$line) {
        $row='<row r="'.$line.'">';
        foreach (array_values($cells) as $index=>$cell) {
            $row.='<c r="'.chr(65+$index).$line.'" t="inlineStr"><is><t xml:space="preserve">'.ct_xml($cell).'</t></is></c>';
        }
        return $row.'</row>';
    };
    $columns=['code'=>'کد سامانه','official_number'=>'شماره رسمی','title'=>'عنوان','contract_type'=>'نوع','legal_status'=>'وضعیت قرارداد',
        'review_status'=>'بازبینی','starts_fa'=>'شروع','ends_fa'=>'پایان','notice_fa'=>'مهلت اخطار','amount'=>'مبلغ','currency'=>'واحد پول',
        'unit_name'=>'واحد مسئول','project_name'=>'پروژه'];
    $labels=[
        'legal_status'=>['unknown'=>'نامشخص','draft'=>'پیش‌نویس','awaiting_signature'=>'در انتظار امضا','active'=>'فعال','suspended'=>'تعلیق‌شده','terminated'=>'خاتمه‌یافته','closed'=>'بسته‌شده'],
        'review_status'=>['provisional'=>'موقت','needs_review'=>'نیازمند بازبینی','verified'=>'تأییدشده']
    ];
    $xml.=$writeRow($columns,1);
    $line=1;
    while ($row=$rows->fetch()) {
        if (++$line>100001) ct_fail(422,'برای خروجی، دامنهٔ جست‌وجو را محدود کنید');
        $row=ct_contract_view($row);
        $data=[];
        foreach ($columns as $key=>$_) $data[]=$labels[$key][$row[$key]] ?? $row[$key];
        $xml.=$writeRow($data,$line);
    }
    $xml.='</sheetData><autoFilter ref="A1:'.chr(64+count($columns)).$line.'"/></worksheet>';
    if (!$zip->addFromString('xl/worksheets/sheet1.xml',$xml) || !$zip->close()) ct_fail(503,'ساخت خروجی ناموفق بود');
    ct_log_download(null,null,'list_xlsx');
    ct_stream($path,'contracts.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',false,true);
}
