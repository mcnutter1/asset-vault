<?php
$pdo = Database::get();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo '<div class="card"><h1>Not Found</h1></div>'; return; }
$st=$pdo->prepare('SELECT * FROM people WHERE id=?'); $st->execute([$id]); $p=$st->fetch();
if (!$p){ echo '<div class="card"><h1>Not Found</h1></div>'; return; }
$name = trim(($p['first_name']??'').' '.($p['last_name']??''));
$contacts=$pdo->prepare('SELECT * FROM person_contacts WHERE person_id=? ORDER BY is_primary DESC, id DESC'); $contacts->execute([$id]); $contacts=$contacts->fetchAll();
$assets=$pdo->prepare('SELECT a.id,a.name,pa.role FROM person_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.person_id=? ORDER BY a.name'); $assets->execute([$id]); $assets=$assets->fetchAll();
try {
  $files=$pdo->prepare("SELECT id, filename, mime_type, size, uploaded_at, caption FROM files WHERE entity_type='person' AND entity_id=? AND is_trashed=0 ORDER BY uploaded_at DESC");
  $files->execute([$id]); $files=$files->fetchAll();
} catch (Throwable $e) {
  $files=$pdo->prepare("SELECT id, filename, mime_type, size, uploaded_at, caption FROM files WHERE entity_type='person' AND entity_id=? ORDER BY uploaded_at DESC");
  $files->execute([$id]); $files=$files->fetchAll();
}
$auth=ensure_authenticated(); $roles=$auth['roles']??[]; $isAdmin=in_array('admin',$roles,true); $priv=[]; $s=$pdo->prepare('SELECT * FROM person_private WHERE person_id=?'); $s->execute([$id]); $priv=$s->fetch()?:[];

function findByCaption($files,$cap){ foreach($files as $f){ if (strtolower(trim($f['caption'] ?? ''))===strtolower($cap)) return $f; } return null; }
function daysUntilBirthday($dob){ if(!$dob) return null; $ts=strtotime($dob); if(!$ts) return null; $m=(int)date('n',$ts); $d=(int)date('j',$ts); $y=(int)date('Y'); $next=strtotime($y.'-'.$m.'-'.$d); if($next<strtotime('today')) $next=strtotime(($y+1).'-'.$m.'-'.$d); $diff=(int)ceil(($next - strtotime('today'))/86400); return $diff; }

$initials = strtoupper(substr($p['first_name']??'',0,1).substr($p['last_name']??'',0,1)); if ($initials==='') $initials='ID';
$pal = [ ['#E8F1FF','#2563eb'], ['#FCE8EC','#db2777'], ['#EEE5FF','#7c3aed'], ['#E6F7F3','#0f766e'], ['#FFF3D6','#b45309'], ['#E6F6FF','#0369a1'] ];
$pidx = abs(crc32($name ?: (string)$id)) % count($pal); $bg=$pal[$pidx][0]; $fg=$pal[$pidx][1];
?>

<div class="person-header">
  <div class="ph-left">
    <div class="ph-avatar" style="background: <?= $bg ?>; color: <?= $fg ?>;"><?= Util::h($initials) ?></div>
    <div class="ph-meta">
      <div class="ph-name"><?= Util::h($name ?: 'Unnamed') ?></div>
      <div class="ph-sub small muted"><?php $days=daysUntilBirthday($p['dob']??null); if($days!==null): ?>Birthday in <?= (int)$days ?> days<?php endif; ?></div>
    </div>
  </div>
  <div class="ph-actions">
    <a class="btn sm" href="<?= Util::baseUrl('index.php?page=person_edit&id='.$id) ?>">Edit</a>
  </div>
</div>

<div class="tabbar">
  <a href="#" class="active" data-tab-link="overview">Overview</a>
  <a href="#" data-tab-link="files">Files</a>
  <a href="#" data-tab-link="notes">Notes</a>
</div>

<div class="person-layout">
  <div class="person-main">
    <div class="card tab-panel show" data-tab-panel="overview">
      <div class="basic-details" style="margin-bottom:12px">
        <div class="section-head"><h2>General Info</h2></div>
        <div class="row">
          <div class="col-6"><label>Birthday</label>
            <div><strong><?= Util::h($p['dob'] ?? '') ?></strong></div>
            <?php $d=daysUntilBirthday($p['dob']??null); if($d!==null): ?><div class="small muted">Birthday in <?= (int)$d ?> days</div><?php endif; ?>
          </div>
          <div class="col-6"><label>Gender</label><div><?= Util::h(ucwords(str_replace('_',' ',$p['gender'] ?? ''))) ?></div></div>
        </div>
      </div>
      <?php
        // Dynamic doc types and values (guard for missing tables)
        try { $docTypes = $pdo->query('SELECT * FROM person_doc_types WHERE is_active=1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { $docTypes = []; }
        try { $st = $pdo->prepare('SELECT dt.* FROM person_docs pd JOIN person_doc_types dt ON dt.id=pd.doc_type_id WHERE pd.person_id=? ORDER BY dt.sort_order, dt.name'); $st->execute([$id]); $activeDocs = $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { $activeDocs = []; }
        $fieldDefs = [];
        foreach ($docTypes as $dt){ $sid=(int)$dt['id']; try { $st=$pdo->prepare('SELECT * FROM person_doc_fields WHERE doc_type_id=? ORDER BY sort_order, display_name'); $st->execute([$sid]); $fieldDefs[$sid]=$st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $fieldDefs[$sid]=[]; } }
        $values = [];
        if (!empty($activeDocs)) { try { $st=$pdo->prepare('SELECT doc_type_id, field_id, value_text FROM person_doc_values WHERE person_id=?'); $st->execute([$id]); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $values[(int)$r['doc_type_id']][(int)$r['field_id']]=$r['value_text']; } } catch (Throwable $e) { $values = []; } }
        function mask_mid($s){ $s=preg_replace('/\D+/','',$s); if(strlen($s)<=4) return $s; return str_repeat('•', max(0, strlen($s)-4)).substr($s,-4); }
      ?>
      <?php if (!$activeDocs): ?>
        <div class="small muted">No documents available.</div>
      <?php endif; ?>
      <?php foreach ($activeDocs as $dt): $code=$dt['code']; $front=findByCaption($files,$code.'_front'); $back=findByCaption($files,$code.'_back'); $tid=(int)$dt['id']; $defs=$fieldDefs[$tid] ?? []; ?>
        <div class="id-row">
          <div class="id-title"><?= Util::h($dt['name']) ?></div>
          <div class="id-slots">
            <?php if ($dt['allow_front_photo']): ?>
              <div class="id-slot">
                <?php if ($front && strpos($front['mime_type'],'image/')===0): ?>
                  <img class="id-prev" src="<?= Util::baseUrl('file.php?id='.(int)$front['id']) ?>" alt="Front">
                <?php else: ?><div class="dz"><div class="dz-title small muted">No file</div></div><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($dt['allow_back_photo']): ?>
              <div class="id-slot">
                <?php if ($back && strpos($back['mime_type'],'image/')===0): ?>
                  <img class="id-prev" src="<?= Util::baseUrl('file.php?id='.(int)$back['id']) ?>" alt="Back">
                <?php else: ?><div class="dz"><div class="dz-title small muted">No file</div></div><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($defs): ?>
            <div class="id-fields row" style="margin-top:6px">
              <?php foreach ($defs as $f): $fid=(int)$f['id']; $val=$values[$tid][$fid] ?? ''; $key=$f['name_key']; ?>
                <div class="col-4"><label><?= Util::h($f['display_name']) ?></label>
                  <div><?= Util::h(in_array($key,['ssn','passport_number','driver_license','boat_license_number','global_entry_number']) ? mask_mid((string)$val) : (string)$val) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card tab-panel" data-tab-panel="files">
      <?php if ($files): ?>
        <div class="table-wrap" style="margin-top:6px"><table>
          <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Caption</th></tr></thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr><td><a href="<?= Util::baseUrl('file.php?id='.(int)$f['id']) ?>" target="_blank"><?= Util::h($f['filename']) ?></a></td><td><?= Util::h($f['mime_type']) ?></td><td><?= number_format($f['size']) ?></td><td><?= Util::h($f['uploaded_at']) ?></td><td><?= Util::h($f['caption'] ?? '') ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="small muted">No files yet.</div>
      <?php endif; ?>
    </div>

    <div class="card tab-panel" data-tab-panel="notes">
      <?php if (!empty($p['notes'])): ?>
        <div class="small"><?= nl2br(Util::h($p['notes'])) ?></div>
      <?php else: ?>
        <div class="small muted">No notes yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="person-side">
    <?php if ($contacts): ?>
      <div class="card">
        <div class="section-head"><h2>Contact Information</h2></div>
        <ul class="list" style="margin-top:8px">
          <?php foreach ($contacts as $c): ?>
            <li class="item"><span><?= Util::h($c['label'] ?: ucfirst($c['contact_type'])) ?></span><span><?= Util::h($c['contact_value']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($assets): ?>
      <div class="card">
        <div class="section-head"><h2>Linked Assets</h2></div>
        <ul class="list" style="margin-top:8px">
          <?php foreach ($assets as $a): ?>
            <li class="item"><a href="<?= Util::baseUrl('index.php?page=asset_edit&id='.(int)$a['id']) ?>"><?= Util::h($a['name']) ?></a><span><?= Util::h($a['role']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php
      // Linked Policies grouped by type: Policy - Coverage - Amount
      $linked = [];
      try {
        $st = $pdo->prepare('SELECT pp.id AS link_id, p.id AS policy_id, p.policy_number, p.insurer, p.policy_type, pp.coverage_definition_id
                              FROM policy_people pp JOIN policies p ON p.id=pp.policy_id
                              WHERE pp.person_id=? ORDER BY p.policy_type, p.insurer, p.policy_number');
        $st->execute([$id]);
        $linked = $st->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) { $linked = []; }
      if ($linked):
        // Map type code -> display name
        $typeNames = [];
        try {
          $hasTypes = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'policy_types'")->fetchColumn();
          if ($hasTypes) {
            foreach ($pdo->query('SELECT code,name FROM policy_types') as $r){ $typeNames[$r['code']] = $r['name']; }
          }
        } catch (Throwable $e) { /* ignore */ }
        // Collect policy IDs and fetch their coverages
        $pids = array_map(function($r){ return (int)$r['policy_id']; }, $linked);
        $pids = array_values(array_unique(array_filter($pids)));
        $covMap = [];
        if ($pids){
          $rows = $pdo->query('SELECT pc.policy_id, pc.coverage_definition_id, cd.name AS coverage_name, pc.limit_amount
                                FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id
                                WHERE pc.policy_id IN ('.implode(',', $pids).')')->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $r){ $covMap[(int)$r['policy_id']][] = ['cov_id'=>(int)$r['coverage_definition_id'],'name'=>$r['coverage_name'],'amount'=>$r['limit_amount']]; }
        }
        // Group into display buckets by policy type
        $byType = [];
        foreach ($linked as $lnk){
          $ptype = (string)($lnk['policy_type'] ?? 'other');
          $typeLabel = $typeNames[$ptype] ?? ucwords(str_replace('_',' ',$ptype));
          $byType[$typeLabel] = $byType[$typeLabel] ?? [];
          $label = trim(($lnk['insurer'] ?: '').' '.($lnk['policy_number'] ?: ''));
          $pid = (int)$lnk['policy_id'];
          $oneCov = $lnk['coverage_definition_id'] ? (int)$lnk['coverage_definition_id'] : null;
          $covs = $covMap[$pid] ?? [];
          if ($oneCov !== null) {
            foreach ($covs as $c){ if ($c['cov_id'] === $oneCov){ $byType[$typeLabel][] = ['policy_id'=>$pid, 'policy_label'=>$label, 'cov'=>$c['name'], 'amt'=>$c['amount']]; break; } }
          } else {
            // Whole policy link: include all coverages (or a placeholder if none)
            if ($covs){ foreach ($covs as $c){ $byType[$typeLabel][] = ['policy_id'=>$pid, 'policy_label'=>$label, 'cov'=>$c['name'], 'amt'=>$c['amount']]; } }
            else { $byType[$typeLabel][] = ['policy_id'=>$pid, 'policy_label'=>$label, 'cov'=>'—', 'amt'=>null]; }
          }
        }
    ?>
      <div class="card">
        <div class="section-head"><h2>Policies</h2></div>
        <?php foreach ($byType as $tname=>$items): ?>
          <div class="small muted" style="margin:6px 0 4px 0"><?= Util::h($tname) ?></div>
          <ul class="list" style="margin-top:4px">
            <?php foreach ($items as $it): $amt = ($it['amt']!==null && $it['amt']!=='') ? ('$'.number_format((float)$it['amt'], 2)) : '—'; ?>
              <li class="item">
                <a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$it['policy_id']) ?>"><?= Util::h($it['policy_label']) ?></a>
                <span><?= Util::h($it['cov']) ?> — <?= Util::h($amt) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
