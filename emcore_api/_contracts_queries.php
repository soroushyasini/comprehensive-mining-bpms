<?php
require_once __DIR__.'/_contracts_import.php';

function ct_lookups()
{
    return [
        'users'=>ct_all("SELECT u.USR_UID AS uid, CONCAT_WS(' ',u.USR_FIRSTNAME,u.USR_LASTNAME) AS name,u.USR_USERNAME AS username
             FROM USERS u JOIN emcore_user_permissions p ON p.usr_uid=u.USR_UID
             WHERE u.USR_STATUS='ACTIVE' AND p.module_key='contracts' AND p.can_read=1 ORDER BY name,u.USR_UID"),
        'companies'=>ct_all('SELECT id,name_fa AS name FROM emcore_companies WHERE deleted_at IS NULL ORDER BY name_fa,id'),
        'persons'=>ct_all("SELECT id,CONCAT_WS(' ',first_name,last_name) AS name FROM emcore_persons WHERE deleted_at IS NULL ORDER BY name,id"),
        'mines'=>ct_all('SELECT id,mine_name AS name FROM emcore_mines WHERE deleted_at IS NULL ORDER BY mine_name,id'),
        'actor_uid'=>ct_actor(),'extensions'=>['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','bmp'],
        'max_upload_bytes'=>50*1024*1024,'ui_version'=>4,
        'contract_types'=>array_column(ct_all("SELECT DISTINCT contract_type FROM emcore_contracts WHERE deleted_at IS NULL AND merged_into IS NULL AND contract_type IS NOT NULL ORDER BY contract_type LIMIT 500"),'contract_type'),
        'open_actions'=>(int)ct_one("SELECT COUNT(*) AS n FROM emcore_contract_actions a JOIN emcore_contracts c ON c.id=a.contract_id WHERE a.status IN ('open','in_progress') AND c.deleted_at IS NULL AND c.merged_into IS NULL")['n']
    ];
}

function ct_categories($id)
{
    return ct_all('SELECT k.*, (SELECT COUNT(*) FROM emcore_contract_history h WHERE h.category_id=k.id AND h.contract_id=? AND h.deleted_at IS NULL) AS record_count
        FROM emcore_contract_categories k WHERE k.contract_id IS NULL OR k.contract_id=? ORDER BY k.sort_order,k.id',[$id,$id]);
}

function ct_list_scope($input)
{
    $args = [];
    $archive = ct_enum($input['archive'] ?? null,['current','archived','all','deleted','merged'],'current');
    $where = [$archive==='merged' ? 'c.merged_into IS NOT NULL' : 'c.merged_into IS NULL'];
    if ($archive==='deleted') {
        emcore_require_permission('contracts','delete');
        $where[]='c.deleted_at IS NOT NULL';
    } else {
        $where[]='c.deleted_at IS NULL';
        if (!in_array($archive,['all','merged'],true)) $where[]='c.archived_at IS '.($archive==='current'?'NULL':'NOT NULL');
    }
    foreach (['review_status','legal_status','contract_type','owner_uid'] as $field) {
        $value = ct_text($input[$field] ?? null,80);
        if ($value!==null) { $where[] = 'c.'.$field.'=?'; $args[]=$value; }
    }
    if (($year=ct_text($input['signed_year'] ?? null,4))!==null) {
        $year=emcore_mh_digits($year);
        if (!preg_match('/^[0-9]{4}$/D',$year)) ct_fail(422,'سال قرارداد نامعتبر است');
        [$startFa,$start]=ct_date($year.'/01/01',true);
        [$nextFa,$next]=ct_date(((int)$year+1).'/01/01',true);
        $where[]='c.signed_on>=? AND c.signed_on<?'; $args[]=$start; $args[]=$next;
    }
    if (($input['without_files'] ?? false)===true) $where[]='NOT EXISTS (SELECT 1 FROM emcore_contract_documents d JOIN emcore_contract_versions v ON v.document_id=d.id AND v.deleted_at IS NULL WHERE d.contract_id=c.id)';
    if (($needle = ct_text($input['search'] ?? null,250)) !== null) {
        $escape = static function ($s) { return '%'.strtr($s,['!'=>'!!','%'=>'!%','_'=>'!_']).'%'; };
        $number = 'c.official_number';
        $digits = preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY);
        foreach ($digits as $i=>$digit) $number = "REPLACE($number,'$digit','".($i%10)."')";
        $where[] = "(c.title LIKE ? ESCAPE '!' OR $number LIKE ? ESCAPE '!' OR
            c.subject LIKE ? ESCAPE '!' OR CONCAT('CTR-',LPAD(c.id,GREATEST(8,CHAR_LENGTH(c.id)),'0')) LIKE ? ESCAPE '!' OR
            EXISTS (SELECT 1 FROM emcore_contract_parties p WHERE p.contract_id=c.id AND p.name LIKE ? ESCAPE '!'))";
        $raw = $escape($needle);
        $normalized = $escape(emcore_mh_digits($needle));
        $args = array_merge($args,[$raw,$normalized,$raw,$normalized,$raw]);
    }
    $sort = ct_enum($input['sort'] ?? null,['id','title','official_number','ends_on','updated_at'],'id');
    $direction = ct_enum($input['direction'] ?? null,['asc','desc'],'desc');
    return [implode(' AND ',$where),$args,'c.'.$sort.' '.$direction.',c.id '.$direction];
}

function ct_list($input)
{
    [$limit,$offset] = ct_page($input);
    [$where,$args,$order] = ct_list_scope($input);
    $total = ct_one("SELECT COUNT(*) AS total FROM emcore_contracts c WHERE $where",$args);
    $rows = ct_all("SELECT c.*,
        (SELECT GROUP_CONCAT(p.name ORDER BY p.id SEPARATOR '، ') FROM emcore_contract_parties p WHERE p.contract_id=c.id AND p.side='other') AS counterparty,
        (SELECT COUNT(*) FROM emcore_contract_documents d WHERE d.contract_id=c.id) AS document_count,
        (SELECT COUNT(*) FROM emcore_contract_versions v JOIN emcore_contract_documents d ON d.id=v.document_id WHERE d.contract_id=c.id AND v.deleted_at IS NULL) AS file_count
        FROM emcore_contracts c WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset",$args);
    return ['items'=>array_map('ct_contract_view',$rows),'total'=>(int)$total['total'],'offset'=>$offset];
}

function ct_detail($id)
{
    $row = ct_row('contracts',$id);
    $data = ['contract'=>ct_contract_view($row),'categories'=>ct_categories($id)];
    foreach (['parties','amendments','obligations'] as $entity) {
        $data[$entity] = ct_all('SELECT * FROM '.ct_table($entity).' WHERE contract_id=?'.
            ($entity==='obligations'?' AND deleted_at IS NULL':'').' ORDER BY id DESC',[$id]);
    }
    $data['summary']=ct_one("SELECT
        (SELECT COUNT(*) FROM emcore_contract_documents WHERE contract_id=?) AS documents,
        (SELECT COUNT(*) FROM emcore_contract_history WHERE contract_id=? AND deleted_at IS NULL) AS history,
        (SELECT COUNT(*) FROM emcore_contract_actions WHERE contract_id=? AND status IN ('open','in_progress')) AS open_actions",[$id,$id,$id]);
    $data['next_action']=ct_one("SELECT id,title,due_fa,due_on,owner_uid FROM emcore_contract_actions WHERE contract_id=? AND status IN ('open','in_progress') ORDER BY due_on IS NULL,due_on,id LIMIT 1",[$id]);
    $data['mines'] = ct_all('SELECT l.mine_id,m.mine_name AS name,m.deleted_at FROM emcore_contract_mines l
        LEFT JOIN emcore_mines m ON m.id=l.mine_id WHERE l.contract_id=? ORDER BY l.mine_id',[$id]);
    $data['duplicate_numbers'] = $row['official_number'] === null ? [] : ct_all(
        "SELECT DISTINCT c.id,c.title FROM emcore_contracts c JOIN emcore_contract_parties p ON p.contract_id=c.id AND p.side='ours'
         JOIN emcore_contract_parties q ON q.contract_id=? AND q.side='ours' AND
            ((p.kind=q.kind AND p.reference_id=q.reference_id) OR p.name=q.name)
         WHERE c.official_number=? AND c.id<>? AND c.deleted_at IS NULL AND c.merged_into IS NULL ORDER BY c.id LIMIT 25",
        [$id,$row['official_number'],$id]);
    return $data;
}

function ct_documents($id,$input)
{
    [$limit,$offset]=ct_page($input);
    $rows=ct_all("SELECT d.*,v.id AS display_version_id,v.original_filename,v.mime_type,v.file_size,v.created_at AS version_created_at, (SELECT COUNT(*) FROM emcore_contract_versions v WHERE v.document_id=d.id AND v.deleted_at IS NULL) AS version_count
        FROM emcore_contract_documents d LEFT JOIN emcore_contract_versions v ON v.id=d.current_version_id AND v.document_id=d.id AND v.deleted_at IS NULL WHERE d.contract_id=? ORDER BY d.id DESC LIMIT ".($limit+1)." OFFSET $offset",[$id]);
    return ['items'=>array_slice($rows,0,$limit),'more'=>count($rows)>$limit];
}

function ct_versions($id,$input)
{
    $doc=ct_row('documents',ct_id($input['document_id'] ?? null),$id);
    $includeDeleted=($input['include_deleted'] ?? false)===true;
    if ($includeDeleted) emcore_require_permission('contracts','delete');
    [$limit,$offset]=ct_page($input);
    $rows=ct_all('SELECT id,document_id,version_no,original_filename,mime_type,file_size,sha256,change_note,created_by,created_at,deleted_at,
        (extracted_text IS NOT NULL) AS has_text FROM emcore_contract_versions WHERE document_id=?'.($includeDeleted?'':' AND deleted_at IS NULL').
        ' ORDER BY version_no DESC LIMIT '.($limit+1)." OFFSET $offset",[$doc['id']]);
    return ['document'=>$doc,'items'=>array_slice($rows,0,$limit),'more'=>count($rows)>$limit];
}

function ct_action_list($input)
{
    [$limit,$offset]=ct_page($input);
    $args=[];
    $filters=['c.deleted_at IS NULL','c.merged_into IS NULL'];
    if (!empty($input['contract_id'])) { $filters[]='a.contract_id=?'; $args[]=ct_id($input['contract_id']); }
    if (($input['mine'] ?? false)===true) { $filters[]='a.owner_uid=?'; $args[]=ct_actor(); }
    if (($input['open_only'] ?? false)===true) $filters[]="a.status IN ('open','in_progress')";
    if (($input['without_due'] ?? false)===true) $filters[]='a.due_on IS NULL';
    $state=ct_enum($input['state'] ?? null,['open','done','cancelled','all'],'all');
    if ($state==='open') $filters[]="a.status IN ('open','in_progress')";
    elseif ($state!=='all') { $filters[]='a.status=?'; $args[]=$state; }
    $total=ct_one('SELECT COUNT(*) AS n FROM emcore_contract_actions a JOIN emcore_contracts c ON c.id=a.contract_id WHERE '.implode(' AND ',$filters),$args);
    $rows=ct_all('SELECT a.*,c.title AS contract_title,k.title AS category_title FROM emcore_contract_actions a
        JOIN emcore_contracts c ON c.id=a.contract_id JOIN emcore_contract_categories k ON k.id=a.category_id
        WHERE '.implode(' AND ',$filters).' ORDER BY a.due_on IS NULL,a.due_on,a.id DESC LIMIT '.($limit+1)." OFFSET $offset",$args);
    return ['items'=>array_slice($rows,0,$limit),'more'=>count($rows)>$limit,'total'=>(int)$total['n']];
}

function ct_case_feed($entity,$id,$input)
{
    [$limit,$offset]=ct_page($input);
    $args=[(string)$id];
    if ($entity==='audit') {
        // History of a case is an explicit part of contracts:read.
        $sql = "SELECT id,actor_usr_uid,action,entity_type,entity_id,before_data,after_data,metadata,created_at
            FROM emcore_audit_log WHERE module_key='contracts' AND JSON_VALID(metadata)
            AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.contract_id'))=? ORDER BY id DESC";
    } elseif ($entity==='history') {
        $category='';
        if (!empty($input['category_id'])) {
            $selected=ct_row('categories',ct_id($input['category_id']));
            if ($selected['contract_id']!==null && (string)$selected['contract_id']!==(string)$id) ct_fail(404,'دسته متعلق به این پرونده نیست');
            $category=' AND h.category_id=?'; $args[]=$selected['id'];
        }
        $sql='SELECT h.*,c.title AS category_title,CONCAT_WS(CHAR(32),u.USR_FIRSTNAME,u.USR_LASTNAME) AS author_name,
            d.title AS legacy_document_title FROM emcore_contract_history h JOIN emcore_contract_categories c ON c.id=h.category_id
            LEFT JOIN USERS u ON u.USR_UID=h.created_by LEFT JOIN emcore_contract_documents d ON d.id=h.document_id AND d.contract_id=h.contract_id
            WHERE h.contract_id=? AND h.deleted_at IS NULL'.$category.' ORDER BY h.happened_on DESC,h.id DESC';
    } elseif ($entity==='evidence') {
        $sql = 'SELECT * FROM emcore_contract_evidence WHERE contract_id=? ORDER BY id DESC';
    } else {
        $sql = 'SELECT s.doc_id,s.bundle_id,s.relative_path,s.sha256,s.metadata,s.version_id
            FROM emcore_contract_sources s JOIN emcore_contract_versions v ON v.id=s.version_id
            JOIN emcore_contract_documents d ON d.id=v.document_id WHERE d.contract_id=? ORDER BY s.id';
    }
    $rows=ct_all($sql.' LIMIT '.($limit+1)." OFFSET $offset",$args);
    if ($entity==='history') $rows=ct_history_files($rows,$id);
    return ['items'=>array_slice($rows,0,$limit),'more'=>count($rows)>$limit];
}

function ct_dashboard()
{
    $today=ct_today();
    $dueSql = "SELECT id AS contract_id,id AS item_id,title,ends_on AS due_on,ends_fa AS due_fa,'end' AS kind,alert_days
        FROM emcore_contracts WHERE deleted_at IS NULL AND merged_into IS NULL AND ends_on IS NOT NULL AND legal_status NOT IN ('draft','terminated','closed')
        UNION ALL SELECT id,id,title,notice_on,notice_fa,'notice',alert_days FROM emcore_contracts
        WHERE deleted_at IS NULL AND merged_into IS NULL AND notice_on IS NOT NULL AND legal_status NOT IN ('draft','terminated','closed')
        UNION ALL SELECT a.contract_id,a.id,a.title,a.due_on,a.due_fa,'action',c.alert_days FROM emcore_contract_actions a
        JOIN emcore_contracts c ON c.id=a.contract_id WHERE c.deleted_at IS NULL AND c.merged_into IS NULL
        AND a.status IN ('open','in_progress') AND a.due_on IS NOT NULL
        UNION ALL SELECT o.contract_id,o.id,o.title,o.due_on,o.due_fa,o.kind,c.alert_days FROM emcore_contract_obligations o
        JOIN emcore_contracts c ON c.id=o.contract_id WHERE c.deleted_at IS NULL AND c.merged_into IS NULL
        AND o.status='open' AND o.due_on IS NOT NULL AND o.deleted_at IS NULL";
    $due = ct_all("SELECT d.*,DATEDIFF(due_on,?) AS days_left FROM ($dueSql) d
        WHERE DATEDIFF(due_on,?)<=alert_days ORDER BY due_on,contract_id,kind,item_id LIMIT 100",[$today,$today]);
    $dueCounts=ct_one("SELECT SUM(due_on<?) AS overdue,SUM(due_on=?) AS today,SUM(DATEDIFF(due_on,?) BETWEEN 1 AND alert_days) AS upcoming
        FROM ($dueSql) d",[$today,$today,$today]);
    $quality=ct_one("SELECT COUNT(*) AS total,SUM(review_status='provisional') AS provisional,
        SUM(review_status='needs_review') AS needs_review,SUM(review_status='verified') AS verified,
        SUM(basis_version_id IS NULL) AS missing_basis,SUM(original_location IS NULL) AS missing_original,
        SUM(review_status<>'verified' AND ends_on IS NULL) AS unverified_dates,
        SUM(review_status<>'verified' AND amount IS NULL) AS unverified_amounts,
        SUM(NOT EXISTS(SELECT 1 FROM emcore_contract_parties p WHERE p.contract_id=c.id AND p.side='ours') OR
            NOT EXISTS(SELECT 1 FROM emcore_contract_parties p WHERE p.contract_id=c.id AND p.side='other')) AS missing_parties
        FROM emcore_contracts c WHERE deleted_at IS NULL AND merged_into IS NULL");
    $actions=ct_one("SELECT COUNT(*) AS open_actions,SUM(a.due_on IS NULL) AS without_due,SUM(u.USR_UID IS NULL OR p.usr_uid IS NULL) AS without_active_owner
        FROM emcore_contract_actions a JOIN emcore_contracts c ON c.id=a.contract_id
        LEFT JOIN USERS u ON u.USR_UID=a.owner_uid AND u.USR_STATUS='ACTIVE'
        LEFT JOIN emcore_user_permissions p ON p.usr_uid=a.owner_uid AND p.module_key='contracts' AND p.can_read=1
        WHERE c.deleted_at IS NULL AND c.merged_into IS NULL AND a.status IN ('open','in_progress')");
    $sources=ct_all('SELECT status,COUNT(*) AS total FROM emcore_contract_sources GROUP BY status');
    $files=ct_one("SELECT COUNT(v.id) AS files,SUM(d.role_code='other') AS unknown_role FROM emcore_contract_versions v
        JOIN emcore_contract_documents d ON d.id=v.document_id JOIN emcore_contracts c ON c.id=d.contract_id
        WHERE v.deleted_at IS NULL AND c.deleted_at IS NULL AND c.merged_into IS NULL");
    $candidateDates = ct_all("SELECT d.contract_id,s.doc_id,JSON_EXTRACT(s.metadata,'$.metadata.date_candidates') AS candidates
        FROM emcore_contract_sources s JOIN emcore_contract_versions v ON v.id=s.version_id
        JOIN emcore_contract_documents d ON d.id=v.document_id JOIN emcore_contracts c ON c.id=d.contract_id
        WHERE c.deleted_at IS NULL AND c.merged_into IS NULL AND c.review_status<>'verified'
        AND JSON_LENGTH(JSON_EXTRACT(s.metadata,'$.metadata.date_candidates'))>0 ORDER BY c.id,s.id LIMIT 100");
    return ['deadlines'=>$due,'quality'=>$quality+$actions+$files+$dueCounts,'sources'=>$sources,'candidate_dates'=>$candidateDates];
}
