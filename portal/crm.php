<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user   = current_user();
$uid    = (int)$user['id'];
$plan   = $user['plan'] ?? 'free';
$is_pro = $plan === 'pro';
$is_ent = $plan === 'entrepreneur';
$is_paid = $is_pro || $is_ent;

// ── Ensure tables exist (idempotent) ──────────────────────────────────────────
try {
    $pdo = get_platform_db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_clients (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          name VARCHAR(120) NOT NULL,
          business VARCHAR(160),
          email VARCHAR(180),
          phone VARCHAR(40),
          city VARCHAR(80),
          industry VARCHAR(80),
          stage ENUM('lead','contacted','proposal','negotiation','won','lost') NOT NULL DEFAULT 'lead',
          deal_value DECIMAL(10,2) DEFAULT 0.00,
          probability TINYINT DEFAULT 50,
          source VARCHAR(80) DEFAULT 'utiligo_lead',
          avatar_color VARCHAR(12) DEFAULT '#3b82f6',
          created_at DATETIME DEFAULT NOW(),
          updated_at DATETIME DEFAULT NOW() ON UPDATE NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_tasks (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          client_id INT,
          title VARCHAR(200) NOT NULL,
          due_date DATE,
          priority ENUM('low','medium','high') DEFAULT 'medium',
          done TINYINT(1) DEFAULT 0,
          created_at DATETIME DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_notes (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          client_id INT,
          body TEXT NOT NULL,
          pinned TINYINT(1) DEFAULT 0,
          created_at DATETIME DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (\Throwable $e) {}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_action'])) {
    header('Content-Type: application/json');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) { echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }
    if (!$is_paid) { echo json_encode(['ok'=>false,'error'=>'upgrade']); exit; }

    $action = $_POST['crm_action'];

    try {
        $pdo = get_platform_db();

        // ── Add client ──
        if ($action === 'add_client') {
            $name  = trim($_POST['name'] ?? '');
            $biz   = trim($_POST['business'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $city  = trim($_POST['city'] ?? '');
            $ind   = trim($_POST['industry'] ?? '');
            $stage = in_array($_POST['stage']??'', ['lead','contacted','proposal','negotiation','won','lost']) ? $_POST['stage'] : 'lead';
            $val   = max(0, (float)($_POST['deal_value'] ?? 0));
            $prob  = min(100, max(0, (int)($_POST['probability'] ?? 50)));
            $colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444'];
            $color  = $colors[array_rand($colors)];
            if (!$name) { echo json_encode(['ok'=>false,'error'=>'Name required']); exit; }
            // Pro cap
            if ($is_pro) {
                $cnt = (int)$pdo->prepare('SELECT COUNT(*) FROM crm_clients WHERE user_id=?')->execute([$uid]) ? $pdo->query('SELECT COUNT(*) FROM crm_clients WHERE user_id='.$uid)->fetchColumn() : 0;
                $s2 = $pdo->prepare('SELECT COUNT(*) FROM crm_clients WHERE user_id=?'); $s2->execute([$uid]); $cnt=(int)$s2->fetchColumn();
                if ($cnt >= 50) { echo json_encode(['ok'=>false,'error'=>'Pro plan limit: 50 clients. Upgrade to Entrepreneur for unlimited.']); exit; }
            }
            $st = $pdo->prepare('INSERT INTO crm_clients (user_id,name,business,email,phone,city,industry,stage,deal_value,probability,avatar_color) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$uid,$name,$biz,$email,$phone,$city,$ind,$stage,$val,$prob,$color]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        }

        // ── Update client stage ──
        elseif ($action === 'update_stage') {
            $id    = (int)$_POST['client_id'];
            $stage = in_array($_POST['stage']??'',['lead','contacted','proposal','negotiation','won','lost'])?$_POST['stage']:'lead';
            $st = $pdo->prepare('UPDATE crm_clients SET stage=? WHERE id=? AND user_id=?');
            $st->execute([$stage,$id,$uid]);
            echo json_encode(['ok'=>true]);
        }

        // ── Update deal value ──
        elseif ($action === 'update_value') {
            $id  = (int)$_POST['client_id'];
            $val = max(0,(float)$_POST['value']);
            $st  = $pdo->prepare('UPDATE crm_clients SET deal_value=? WHERE id=? AND user_id=?');
            $st->execute([$val,$id,$uid]);
            echo json_encode(['ok'=>true]);
        }

        // ── Delete client ──
        elseif ($action === 'delete_client') {
            $id = (int)$_POST['client_id'];
            $pdo->prepare('DELETE FROM crm_clients WHERE id=? AND user_id=?')->execute([$id,$uid]);
            echo json_encode(['ok'=>true]);
        }

        // ── Add task ──
        elseif ($action === 'add_task') {
            $title = trim($_POST['title']??'');
            $due   = $_POST['due_date']??null;
            $pri   = in_array($_POST['priority']??'',['low','medium','high'])?$_POST['priority']:'medium';
            $cid   = (int)($_POST['client_id']??0) ?: null;
            if (!$title) { echo json_encode(['ok'=>false,'error'=>'Title required']); exit; }
            $st = $pdo->prepare('INSERT INTO crm_tasks (user_id,client_id,title,due_date,priority) VALUES (?,?,?,?,?)');
            $st->execute([$uid,$cid,$title,$due?:null,$pri]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        }

        // ── Toggle task done ──
        elseif ($action === 'toggle_task') {
            $id = (int)$_POST['task_id'];
            $pdo->prepare('UPDATE crm_tasks SET done=1-done WHERE id=? AND user_id=?')->execute([$id,$uid]);
            echo json_encode(['ok'=>true]);
        }

        // ── Delete task ──
        elseif ($action === 'delete_task') {
            $id = (int)$_POST['task_id'];
            $pdo->prepare('DELETE FROM crm_tasks WHERE id=? AND user_id=?')->execute([$id,$uid]);
            echo json_encode(['ok'=>true]);
        }

        // ── Add note (ENT only) ──
        elseif ($action === 'add_note') {
            if (!$is_ent) { echo json_encode(['ok'=>false,'error'=>'Notes require Entrepreneur plan']); exit; }
            $body = trim($_POST['body']??'');
            $cid  = (int)($_POST['client_id']??0) ?: null;
            $pin  = !empty($_POST['pinned']) ? 1 : 0;
            if (!$body) { echo json_encode(['ok'=>false,'error'=>'Note body required']); exit; }
            $st = $pdo->prepare('INSERT INTO crm_notes (user_id,client_id,body,pinned) VALUES (?,?,?,?)');
            $st->execute([$uid,$cid,$body,$pin]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        }

        // ── Delete note ──
        elseif ($action === 'delete_note') {
            if (!$is_ent) { echo json_encode(['ok'=>false,'error'=>'upgrade']); exit; }
            $id = (int)$_POST['note_id'];
            $pdo->prepare('DELETE FROM crm_notes WHERE id=? AND user_id=?')->execute([$id,$uid]);
            echo json_encode(['ok'=>true]);
        }

        // ── CSV export (ENT only) ──
        elseif ($action === 'export_csv') {
            if (!$is_ent) { echo json_encode(['ok'=>false,'error'=>'upgrade']); exit; }
            $rows = $pdo->prepare('SELECT name,business,email,phone,city,industry,stage,deal_value,probability,created_at FROM crm_clients WHERE user_id=? ORDER BY created_at DESC');
            $rows->execute([$uid]);
            $csv = "Name,Business,Email,Phone,City,Industry,Stage,Deal Value,Probability,Added\n";
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $csv .= implode(',', array_map(fn($v)=>'"'.str_replace('"','""',$v).'"', $r))."\n";
            }
            echo json_encode(['ok'=>true,'csv'=>$csv]);
        }

        else { echo json_encode(['ok'=>false,'error'=>'Unknown action']); }

    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Load data for page render ─────────────────────────────────────────────────
$clients = []; $tasks = []; $notes = [];
$stats   = ['total'=>0,'won'=>0,'pipeline_value'=>0,'won_value'=>0,'lost'=>0,'conversion'=>0];

if ($is_paid) {
    try {
        $pdo = get_platform_db();

        $s = $pdo->prepare('SELECT * FROM crm_clients WHERE user_id=? ORDER BY updated_at DESC');
        $s->execute([$uid]); $clients = $s->fetchAll(PDO::FETCH_ASSOC);

        $s2 = $pdo->prepare('SELECT * FROM crm_tasks WHERE user_id=? ORDER BY done ASC, due_date ASC, created_at DESC');
        $s2->execute([$uid]); $tasks = $s2->fetchAll(PDO::FETCH_ASSOC);

        if ($is_ent) {
            $s3 = $pdo->prepare('SELECT n.*,c.name AS client_name FROM crm_notes n LEFT JOIN crm_clients c ON c.id=n.client_id WHERE n.user_id=? ORDER BY n.pinned DESC, n.created_at DESC');
            $s3->execute([$uid]); $notes = $s3->fetchAll(PDO::FETCH_ASSOC);
        }

        // Stats
        $stats['total'] = count($clients);
        foreach ($clients as $c) {
            if ($c['stage'] === 'won')  { $stats['won']++;  $stats['won_value']  += $c['deal_value']; }
            if ($c['stage'] === 'lost') { $stats['lost']++; }
            if (!in_array($c['stage'],['won','lost'])) $stats['pipeline_value'] += $c['deal_value'] * ($c['probability']/100);
        }
        if ($stats['total'] > 0) $stats['conversion'] = round($stats['won'] / $stats['total'] * 100);

    } catch (\Throwable $e) {}
}

// Pipeline stage config
$stages = [
    'lead'        => ['label'=>'Lead',        'color'=>'#3b82f6','icon'=>'fa-user-plus'],
    'contacted'   => ['label'=>'Contacted',   'color'=>'#f59e0b','icon'=>'fa-envelope'],
    'proposal'    => ['label'=>'Proposal',    'color'=>'#8b5cf6','icon'=>'fa-file-lines'],
    'negotiation' => ['label'=>'Negotiation', 'color'=>'#ec4899','icon'=>'fa-handshake'],
    'won'         => ['label'=>'Won',         'color'=>'#10b981','icon'=>'fa-trophy'],
    'lost'        => ['label'=>'Lost',        'color'=>'#ef4444','icon'=>'fa-xmark'],
];

// Group clients by stage
$by_stage = array_fill_keys(array_keys($stages), []);
foreach ($clients as $c) { $by_stage[$c['stage']][] = $c; }

// Revenue by month (last 6 months, won deals)
$monthly_rev = [];
for ($i=5;$i>=0;$i--) {
    $key = date('M Y', strtotime("-$i months"));
    $monthly_rev[$key] = 0;
}
foreach ($clients as $c) {
    if ($c['stage'] !== 'won') continue;
    $key = date('M Y', strtotime($c['updated_at']));
    if (isset($monthly_rev[$key])) $monthly_rev[$key] += $c['deal_value'];
}

$csrf = csrf_token();
$pageTitle = 'Client CRM — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
.crm-tab { padding:8px 18px; border-radius:10px; font-size:.8rem; font-weight:600; color:#64748b; cursor:pointer; transition:all .15s; white-space:nowrap; border:none; background:none; }
.crm-tab:hover { color:#fff; background:rgba(255,255,255,.06); }
.crm-tab.active { color:#fff; background:rgba(255,255,255,.1); }
.crm-tab-pane { display:none; }
.crm-tab-pane.active { display:block; }
.stage-col { min-width:220px; }
.pipeline-card { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:14px; padding:12px 14px; cursor:grab; transition:box-shadow .15s,transform .15s; }
.pipeline-card:hover { box-shadow:0 4px 20px rgba(0,0,0,.4); transform:translateY(-2px); }
.stage-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:6px; flex-shrink:0; }
.prob-bar { height:3px; border-radius:2px; background:rgba(255,255,255,.08); }
.prob-fill { height:100%; border-radius:2px; transition:width .4s; }
.rev-bar-wrap { display:flex; align-items:flex-end; gap:6px; height:80px; }
.rev-bar { flex:1; border-radius:4px 4px 0 0; background:rgba(255,255,255,.15); transition:height .5s cubic-bezier(.4,0,.2,1); min-height:4px; position:relative; cursor:default; }
.rev-bar:hover { background:rgba(255,255,255,.3); }
.rev-bar .rev-tip { position:absolute; bottom:calc(100% + 4px); left:50%; transform:translateX(-50%); background:#1e293b; border:1px solid rgba(255,255,255,.1); color:#fff; font-size:.65rem; font-weight:700; padding:2px 6px; border-radius:6px; white-space:nowrap; opacity:0; transition:opacity .15s; pointer-events:none; }
.rev-bar:hover .rev-tip { opacity:1; }
.task-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid rgba(255,255,255,.05); }
.task-row:last-child { border-bottom:none; }
.task-check { width:18px; height:18px; border-radius:5px; border:1.5px solid rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; transition:all .15s; }
.task-check.done { background:#10b981; border-color:#10b981; }
.pri-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.note-card { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:12px; padding:14px 16px; }
.note-card.pinned { border-color:rgba(251,191,36,.2); background:rgba(251,191,36,.03); }
.modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:100; display:flex; align-items:center; justify-content:center; padding:16px; opacity:0; pointer-events:none; transition:opacity .2s; }
.modal-backdrop.open { opacity:1; pointer-events:all; }
.modal-box { background:#0f172a; border:1px solid rgba(255,255,255,.1); border-radius:20px; width:100%; max-width:520px; max-height:90vh; overflow-y:auto; padding:28px; transform:translateY(12px); transition:transform .2s; }
.modal-backdrop.open .modal-box { transform:translateY(0); }
.crm-input { width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); color:#f1f5f9; font-size:.875rem; padding:.6rem .75rem; border-radius:10px; outline:none; transition:border-color .15s; }
.crm-input:focus { border-color:rgba(255,255,255,.22); }
.crm-input::placeholder { color:#475569; }
select.crm-input option { background:#0f172a; }
.upgrade-wall { border:1px dashed rgba(255,255,255,.1); border-radius:16px; padding:40px 24px; text-align:center; }
@keyframes crm-in { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.crm-in { animation:crm-in .22s ease both; }
</style>

<!-- PAGE HEADER -->
<div class="mb-6 flex items-start justify-between flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-bold tracking-tight">Client CRM</h1>
    <p class="text-slate-500 text-sm mt-1">Track every deal from first contact to closed &amp; paid.</p>
  </div>
  <?php if ($is_paid): ?>
  <div class="flex items-center gap-2">
    <?php if ($is_ent): ?>
    <button onclick="exportCSV()" class="flex items-center gap-2 text-xs font-semibold text-slate-400 hover:text-white bg-white/5 border border-white/8 px-3 py-2 rounded-xl transition">
      <i class="fa-solid fa-download text-[10px]"></i> Export CSV
    </button>
    <?php endif; ?>
    <button onclick="openAddClient()" class="flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-4 py-2 rounded-xl text-sm font-bold transition">
      <i class="fa-solid fa-plus text-xs"></i> Add Client
    </button>
  </div>
  <?php endif; ?>
</div>

<!-- PLAN GATE: free users see upgrade wall -->
<?php if (!$is_paid): ?>
<div class="upgrade-wall">
  <div class="w-16 h-16 rounded-2xl bg-white/5 border border-white/8 flex items-center justify-center mx-auto mb-5">
    <i class="fa-solid fa-address-book text-3xl text-slate-500"></i>
  </div>
  <h2 class="text-xl font-bold mb-2">Client CRM</h2>
  <p class="text-slate-400 text-sm max-w-sm mx-auto mb-6">Track your entire sales pipeline, monitor revenue, manage tasks, and keep notes on every client &mdash; all in one place. Available on Pro and Entrepreneur plans.</p>
  <div class="flex flex-col sm:flex-row items-center justify-center gap-3 mb-6">
    <a href="/portal/billing?upgrade=1&plan=pro" class="bg-white hover:bg-slate-200 text-black px-6 py-2.5 rounded-full font-bold text-sm transition">
      <i class="fa-solid fa-crown mr-2"></i>Go Pro &mdash; $21.99/mo
    </a>
    <a href="/portal/billing?upgrade=1&plan=entrepreneur" class="bg-white/10 hover:bg-white/20 text-white px-6 py-2.5 rounded-full font-bold text-sm transition">
      <i class="fa-solid fa-rocket mr-2"></i>Entrepreneur &mdash; $49.99/mo
    </a>
  </div>
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 max-w-lg mx-auto text-left">
    <?php foreach ([
      ['fa-filter','Pipeline board','Kanban-style deal tracker'],
      ['fa-chart-bar','Revenue tracker','Monthly bar chart + stats'],
      ['fa-users','Client list','Full contact directory'],
      ['fa-list-check','Task manager','Follow-up reminders'],
      ['fa-sticky-note','Smart notes','ENT only — pin &amp; tag'],
      ['fa-file-csv','CSV export','ENT only — bulk export'],
    ] as [$ic,$t,$s]):?>
    <div class="glass rounded-xl p-3">
      <i class="fa-solid <?=$ic?> text-slate-500 mb-2 text-sm"></i>
      <p class="text-xs font-semibold text-white"><?=$t?></p>
      <p class="text-[11px] text-slate-600"><?=$s?></p>
    </div>
    <?php endforeach;?>
  </div>
</div>

<?php else: ?>

<!-- STAT TILES -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
  <?php
  $tiles = [
    ['fa-users',       'Total Clients',     $stats['total'],                               ''],
    ['fa-trophy',      'Won Deals',          $stats['won'],                                '#10b981'],
    ['fa-sack-dollar', 'Revenue Won',       '$'.number_format($stats['won_value'],0),       '#10b981'],
    ['fa-filter',      'Pipeline Value',    '$'.number_format($stats['pipeline_value'],0),  '#8b5cf6'],
  ];
  foreach ($tiles as [$ic,$lbl,$val,$col]):?>
  <div class="glass rounded-2xl p-4 crm-in">
    <div class="flex items-center gap-2 mb-2">
      <i class="fa-solid <?=$ic?> text-xs" style="color:<?=$col?:"#64748b"?>"></i>
      <span class="text-[10px] font-semibold text-slate-600 uppercase tracking-wide"><?=$lbl?></span>
    </div>
    <p class="text-2xl font-extrabold text-white"><?=$val?></p>
    <?php if($lbl==='Won Deals' && $stats['total']>0):?>
    <p class="text-[11px] text-slate-600 mt-0.5"><?=$stats['conversion']?>% conversion</p>
    <?php endif;?>
  </div>
  <?php endforeach;?>
</div>

<!-- TABS -->
<div class="flex gap-1 overflow-x-auto pb-1 mb-6" style="scrollbar-width:none;">
  <button class="crm-tab active" onclick="switchTab('pipeline',this)"><i class="fa-solid fa-filter mr-1.5"></i>Pipeline</button>
  <button class="crm-tab" onclick="switchTab('revenue',this)"><i class="fa-solid fa-chart-bar mr-1.5"></i>Revenue</button>
  <button class="crm-tab" onclick="switchTab('clients',this)"><i class="fa-solid fa-users mr-1.5"></i>Clients</button>
  <button class="crm-tab" onclick="switchTab('tasks',this)"><i class="fa-solid fa-list-check mr-1.5"></i>Tasks
    <?php $open_tasks = count(array_filter($tasks,fn($t)=>!$t['done'])); if($open_tasks>0):?><span class="ml-1 text-[10px] bg-white/10 text-white rounded-full px-1.5 py-0.5"><?=$open_tasks?></span><?php endif;?>
  </button>
  <?php if ($is_ent):?>
  <button class="crm-tab" onclick="switchTab('notes',this)"><i class="fa-solid fa-sticky-note mr-1.5"></i>Notes</button>
  <?php endif;?>
</div>


<!-- ════════════ PIPELINE TAB ════════════ -->
<div id="tab-pipeline" class="crm-tab-pane active">
  <?php if (empty($clients)):?>
  <div class="upgrade-wall py-16">
    <i class="fa-solid fa-filter text-3xl text-slate-700 mb-4"></i>
    <p class="text-sm font-semibold text-slate-400 mb-1">Your pipeline is empty</p>
    <p class="text-xs text-slate-600">Add your first client to start tracking deals.</p>
  </div>
  <?php else:?>
  <div class="overflow-x-auto pb-4">
    <div class="flex gap-4" style="min-width:max-content;">
      <?php foreach ($stages as $sk => $sd):?>
      <?php $col_clients = $by_stage[$sk]; ?>
      <div class="stage-col flex-shrink-0">
        <!-- Stage header -->
        <div class="flex items-center gap-2 mb-3 px-1">
          <span class="stage-dot" style="background:<?=$sd['color']?>"></span>
          <span class="text-xs font-bold text-white"><?=$sd['label']?></span>
          <span class="text-[10px] text-slate-600 bg-white/5 rounded-full px-1.5 ml-auto"><?=count($col_clients)?></span>
        </div>
        <!-- Cards -->
        <div class="space-y-2" id="stage-col-<?=$sk?>">
          <?php foreach ($col_clients as $c):?>
          <div class="pipeline-card crm-in" data-id="<?=$c['id']?>">
            <div class="flex items-center gap-2 mb-2">
              <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0" style="background:<?=$c['avatar_color']?>">
                <?=strtoupper(substr($c['name'],0,1))?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-white truncate"><?=htmlspecialchars($c['name'])?></p>
                <p class="text-[10px] text-slate-600 truncate"><?=htmlspecialchars($c['business']??'')?></p>
              </div>
              <button onclick="deleteClient(<?=$c['id']?>)" class="text-slate-700 hover:text-red-400 text-[10px] transition"><i class="fa-solid fa-trash"></i></button>
            </div>
            <?php if ($c['deal_value'] > 0):?>
            <div class="flex items-center justify-between mb-1.5">
              <span class="text-[10px] text-slate-500">Value</span>
              <span class="text-xs font-bold text-white">$<?=number_format($c['deal_value'],0)?></span>
            </div>
            <div class="prob-bar mb-1.5"><div class="prob-fill" style="width:<?=$c['probability']?>%;background:<?=$sd['color']?>"></div></div>
            <p class="text-[10px] text-slate-700"><?=$c['probability']?>% probability</p>
            <?php endif;?>
            <div class="mt-2.5">
              <select onchange="updateStage(<?=$c['id']?>,this.value)" class="w-full bg-white/5 border border-white/8 text-[11px] text-slate-400 rounded-lg px-2 py-1.5 outline-none">
                <?php foreach ($stages as $sk2=>$sd2):?>
                <option value="<?=$sk2?>" <?=$c['stage']===$sk2?'selected':''?>><?=$sd2['label']?></option>
                <?php endforeach;?>
              </select>
            </div>
          </div>
          <?php endforeach;?>
          <?php if (empty($col_clients)):?>
          <div class="text-center py-8 text-[11px] text-slate-700 border border-dashed border-white/5 rounded-xl">
            No clients
          </div>
          <?php endif;?>
        </div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
  <?php endif;?>
</div>


<!-- ════════════ REVENUE TAB ════════════ -->
<div id="tab-revenue" class="crm-tab-pane">
  <!-- Bar chart -->
  <div class="glass rounded-2xl p-5 mb-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-bold">Monthly Revenue (Won Deals)</h3>
      <span class="text-xs text-slate-600">Last 6 months</span>
    </div>
    <?php $max_rev = max(1, max($monthly_rev)); ?>
    <div class="rev-bar-wrap">
      <?php foreach ($monthly_rev as $mo => $val):?>
      <div class="rev-bar" style="height:<?=max(4,round($val/$max_rev*80))?>px">
        <span class="rev-tip">$<?=number_format($val,0)?></span>
      </div>
      <?php endforeach;?>
    </div>
    <div class="flex gap-1.5 mt-2">
      <?php foreach (array_keys($monthly_rev) as $mo):?>
      <div class="flex-1 text-[9px] text-slate-700 text-center truncate"><?=$mo?></div>
      <?php endforeach;?>
    </div>
  </div>

  <!-- Revenue breakdown -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
    <?php
    $total_pipeline = array_sum(array_map(fn($c)=>$c['stage']==='won'||$c['stage']==='lost'?0:($c['deal_value']*$c['probability']/100),$clients));
    $avg_deal = $stats['won']>0 ? $stats['won_value']/$stats['won'] : 0;
    $lost_val = array_sum(array_map(fn($c)=>$c['stage']==='lost'?$c['deal_value']:0,$clients));
    foreach ([
      ['fa-sack-dollar','Total Won',      '$'.number_format($stats['won_value'],2), 'Closed revenue'],
      ['fa-chart-line', 'Avg Deal Size',  '$'.number_format($avg_deal,0),           $stats['won'].' won deal'.($stats['won']!==1?'s':'')],
      ['fa-filter',     'Weighted Pipeline','$'.number_format($total_pipeline,0),  'Probability-adjusted'],
    ] as [$ic,$lbl,$val,$sub]):?>
    <div class="glass rounded-2xl p-4">
      <i class="fa-solid <?=$ic?> text-slate-500 text-sm mb-3"></i>
      <p class="text-xl font-extrabold text-white"><?=$val?></p>
      <p class="text-xs font-semibold text-slate-400 mt-0.5"><?=$lbl?></p>
      <p class="text-[11px] text-slate-700 mt-0.5"><?=$sub?></p>
    </div>
    <?php endforeach;?>
  </div>

  <!-- Stage breakdown table -->
  <div class="glass rounded-2xl overflow-hidden">
    <div class="px-5 py-3 border-b border-white/5">
      <h3 class="text-sm font-bold">Deals by Stage</h3>
    </div>
    <table class="w-full text-sm">
      <thead><tr class="text-[10px] text-slate-600 uppercase tracking-wide border-b border-white/5">
        <th class="px-5 py-2.5 text-left">Stage</th>
        <th class="px-5 py-2.5 text-right">Count</th>
        <th class="px-5 py-2.5 text-right">Total Value</th>
        <th class="px-5 py-2.5 text-right">% of Pipeline</th>
      </tr></thead>
      <tbody>
        <?php
        $grand = array_sum(array_column($clients,'deal_value'));
        foreach ($stages as $sk=>$sd):
          $sc = $by_stage[$sk];
          $sv = array_sum(array_column($sc,'deal_value'));
          $pct = $grand>0 ? round($sv/$grand*100) : 0;
        ?>
        <tr class="border-b border-white/5 hover:bg-white/3 transition">
          <td class="px-5 py-3">
            <span class="stage-dot" style="background:<?=$sd['color']?>"></span>
            <?=$sd['label']?>
          </td>
          <td class="px-5 py-3 text-right text-slate-400"><?=count($sc)?></td>
          <td class="px-5 py-3 text-right font-semibold">$<?=number_format($sv,0)?></td>
          <td class="px-5 py-3 text-right text-slate-400"><?=$pct?>%</td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>


<!-- ════════════ CLIENTS TAB ════════════ -->
<div id="tab-clients" class="crm-tab-pane">
  <?php if ($is_pro):?>
  <p class="text-xs text-slate-600 mb-4"><?=count($clients)?> / 50 clients &bull; <a href="/portal/billing?plan=entrepreneur" class="text-white hover:underline">Upgrade for unlimited</a></p>
  <?php else:?>
  <p class="text-xs text-slate-600 mb-4"><?=count($clients)?> clients (unlimited)</p>
  <?php endif;?>

  <?php if (empty($clients)):?>
  <div class="upgrade-wall py-16">
    <i class="fa-solid fa-users text-3xl text-slate-700 mb-4"></i>
    <p class="text-sm font-semibold text-slate-400">No clients yet &mdash; add one above.</p>
  </div>
  <?php else:?>
  <!-- Search/filter bar -->
  <div class="flex gap-2 mb-4">
    <div class="relative flex-1">
      <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-600 text-xs"></i>
      <input type="text" id="clientSearch" placeholder="Search clients..." oninput="filterClients()" class="crm-input pl-8">
    </div>
    <select id="stageFilter" onchange="filterClients()" class="crm-input" style="max-width:140px">
      <option value="">All stages</option>
      <?php foreach ($stages as $sk=>$sd):?>
      <option value="<?=$sk?>"><?=$sd['label']?></option>
      <?php endforeach;?>
    </select>
  </div>

  <div class="space-y-2" id="clientTable">
    <?php foreach ($clients as $c):?>
    <div class="glass rounded-2xl p-4 flex items-center gap-3 flex-wrap crm-in client-row"
         data-name="<?=strtolower(htmlspecialchars($c['name']))?>"
         data-biz="<?=strtolower(htmlspecialchars($c['business']??''))?>"
         data-stage="<?=$c['stage']?>">
      <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white shrink-0" style="background:<?=$c['avatar_color']?>">
        <?=strtoupper(substr($c['name'],0,1))?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-white"><?=htmlspecialchars($c['name'])?></p>
        <p class="text-xs text-slate-500"><?=htmlspecialchars($c['business']??'')?> <?=$c['city']?"&bull; ".htmlspecialchars($c['city']):''?></p>
      </div>
      <div class="hidden sm:flex items-center gap-1">
        <span class="stage-dot" style="background:<?=$stages[$c['stage']]['color']?>"></span>
        <span class="text-xs text-slate-400"><?=$stages[$c['stage']]['label']?></span>
      </div>
      <div class="text-sm font-bold text-white min-w-[60px] text-right">
        <?=$c['deal_value']>0?'$'.number_format($c['deal_value'],0):'&mdash;'?>
      </div>
      <?php if ($c['email']):?>
      <a href="mailto:<?=htmlspecialchars($c['email'])?>" class="text-slate-600 hover:text-white text-xs transition"><i class="fa-solid fa-envelope"></i></a>
      <?php endif;?>
      <?php if ($c['phone']):?>
      <a href="tel:<?=htmlspecialchars($c['phone'])?>" class="text-slate-600 hover:text-white text-xs transition"><i class="fa-solid fa-phone"></i></a>
      <?php endif;?>
      <button onclick="deleteClient(<?=$c['id']?>)" class="text-slate-700 hover:text-red-400 text-xs transition"><i class="fa-solid fa-trash"></i></button>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</div>


<!-- ════════════ TASKS TAB ════════════ -->
<div id="tab-tasks" class="crm-tab-pane">
  <div class="flex items-center justify-between mb-4">
    <h3 class="text-sm font-bold">Follow-up Tasks</h3>
    <button onclick="openAddTask()" class="flex items-center gap-1.5 text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1.5 rounded-xl transition">
      <i class="fa-solid fa-plus text-[10px]"></i> Add Task
    </button>
  </div>

  <?php if (empty($tasks)):?>
  <div class="upgrade-wall py-16">
    <i class="fa-solid fa-list-check text-3xl text-slate-700 mb-4"></i>
    <p class="text-sm font-semibold text-slate-400">No tasks yet &mdash; add one to stay on top of follow-ups.</p>
  </div>
  <?php else:?>
  <div class="glass rounded-2xl px-4 py-2" id="taskList">
    <?php foreach ($tasks as $t):
      $pri_colors = ['low'=>'#64748b','medium'=>'#f59e0b','high'=>'#ef4444'];
      $pri_color  = $pri_colors[$t['priority']];
      $overdue    = $t['due_date'] && !$t['done'] && $t['due_date'] < date('Y-m-d');
    ?>
    <div class="task-row crm-in" id="task-<?=$t['id']?>">
      <div class="task-check <?=$t['done']?'done':''?>" onclick="toggleTask(<?=$t['id']?>,this)">
        <?php if($t['done']):?><i class="fa-solid fa-check text-white text-[9px]"></i><?php endif;?>
      </div>
      <span class="pri-dot" style="background:<?=$pri_color?>"></span>
      <div class="flex-1">
        <p class="text-sm <?=$t['done']?'line-through text-slate-600':'text-white'?>"><?=htmlspecialchars($t['title'])?></p>
        <?php if($t['due_date']):?>
        <p class="text-[11px] <?=$overdue?'text-red-400':'text-slate-600'?>"><?=$overdue?'Overdue — ':''?><?=date('M j, Y',strtotime($t['due_date']))?></p>
        <?php endif;?>
      </div>
      <span class="text-[10px] font-semibold capitalize" style="color:<?=$pri_color?>"><?=$t['priority']?></span>
      <button onclick="deleteTask(<?=$t['id']?>)" class="text-slate-700 hover:text-red-400 text-xs transition"><i class="fa-solid fa-trash"></i></button>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</div>


<?php if ($is_ent):?>
<!-- ════════════ NOTES TAB ════════════ -->
<div id="tab-notes" class="crm-tab-pane">
  <div class="flex items-center justify-between mb-4">
    <h3 class="text-sm font-bold">Smart Notes</h3>
    <button onclick="openAddNote()" class="flex items-center gap-1.5 text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1.5 rounded-xl transition">
      <i class="fa-solid fa-plus text-[10px]"></i> New Note
    </button>
  </div>
  <?php if (empty($notes)):?>
  <div class="upgrade-wall py-16">
    <i class="fa-solid fa-sticky-note text-3xl text-slate-700 mb-4"></i>
    <p class="text-sm font-semibold text-slate-400">No notes yet &mdash; jot down anything about a client or deal.</p>
  </div>
  <?php else:?>
  <div class="grid sm:grid-cols-2 gap-3" id="noteGrid">
    <?php foreach ($notes as $n):?>
    <div class="note-card crm-in <?=$n['pinned']?'pinned':''?>" id="note-<?=$n['id']?>">
      <div class="flex items-start justify-between gap-2 mb-2">
        <div class="flex items-center gap-1.5">
          <?php if($n['pinned']):?><i class="fa-solid fa-thumbtack text-amber-400 text-[10px]"></i><?php endif;?>
          <?php if($n['client_name']):?><span class="text-[10px] font-semibold text-slate-500"><?=htmlspecialchars($n['client_name'])?></span><?php endif;?>
        </div>
        <button onclick="deleteNote(<?=$n['id']?>)" class="text-slate-700 hover:text-red-400 text-[10px] transition shrink-0"><i class="fa-solid fa-trash"></i></button>
      </div>
      <p class="text-sm text-slate-300 leading-relaxed whitespace-pre-wrap"><?=htmlspecialchars($n['body'])?></p>
      <p class="text-[10px] text-slate-700 mt-3"><?=date('M j, Y g:i A',strtotime($n['created_at']))?></p>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</div>
<?php endif;?>

<?php endif; // end $is_paid ?>


<!-- ════════ MODALS ════════ -->

<!-- Add Client Modal -->
<div id="modalAddClient" class="modal-backdrop">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-6">
      <h3 class="text-lg font-bold">Add Client</h3>
      <button onclick="closeModal('modalAddClient')" class="w-8 h-8 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-slate-400 hover:text-white transition">
        <i class="fa-solid fa-xmark text-xs"></i>
      </button>
    </div>
    <form id="formAddClient" class="space-y-3">
      <input type="hidden" name="crm_action" value="add_client">
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Name *</label>
          <input type="text" name="name" required class="crm-input" placeholder="Jane Smith">
        </div>
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Business</label>
          <input type="text" name="business" class="crm-input" placeholder="Smith Plumbing">
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Email</label>
          <input type="email" name="email" class="crm-input" placeholder="jane@example.com">
        </div>
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Phone</label>
          <input type="text" name="phone" class="crm-input" placeholder="+1 555 000 0000">
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">City</label>
          <input type="text" name="city" class="crm-input" placeholder="Calgary">
        </div>
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Industry</label>
          <input type="text" name="industry" class="crm-input" placeholder="Plumber">
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Stage</label>
          <select name="stage" class="crm-input">
            <?php foreach ($stages as $sk=>$sd):?><option value="<?=$sk?>"><?=$sd['label']?></option><?php endforeach;?>
          </select>
        </div>
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Deal Value ($)</label>
          <input type="number" name="deal_value" min="0" step="0.01" class="crm-input" placeholder="500">
        </div>
      </div>
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Win Probability (%): <span id="probDisplay">50</span></label>
        <input type="range" name="probability" min="0" max="100" value="50" oninput="document.getElementById('probDisplay').textContent=this.value" class="w-full accent-white">
      </div>
      <div id="addClientError" class="hidden text-xs text-red-400 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2"></div>
      <button type="submit" class="w-full bg-white hover:bg-slate-200 text-black py-3 rounded-xl font-bold text-sm transition">
        <span id="addClientBtnLabel">Add Client</span>
      </button>
    </form>
  </div>
</div>

<!-- Add Task Modal -->
<div id="modalAddTask" class="modal-backdrop">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-6">
      <h3 class="text-lg font-bold">Add Task</h3>
      <button onclick="closeModal('modalAddTask')" class="w-8 h-8 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-slate-400 hover:text-white transition">
        <i class="fa-solid fa-xmark text-xs"></i>
      </button>
    </div>
    <form id="formAddTask" class="space-y-3">
      <input type="hidden" name="crm_action" value="add_task">
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Task *</label>
        <input type="text" name="title" required class="crm-input" placeholder="Follow up with Jane Smith">
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Due Date</label>
          <input type="date" name="due_date" class="crm-input">
        </div>
        <div>
          <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Priority</label>
          <select name="priority" class="crm-input">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Link to Client (optional)</label>
        <select name="client_id" class="crm-input">
          <option value="">-- None --</option>
          <?php foreach ($clients as $c):?>
          <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach;?>
        </select>
      </div>
      <button type="submit" class="w-full bg-white hover:bg-slate-200 text-black py-3 rounded-xl font-bold text-sm transition">Add Task</button>
    </form>
  </div>
</div>

<?php if ($is_ent):?>
<!-- Add Note Modal -->
<div id="modalAddNote" class="modal-backdrop">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-6">
      <h3 class="text-lg font-bold">New Note</h3>
      <button onclick="closeModal('modalAddNote')" class="w-8 h-8 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-slate-400 hover:text-white transition">
        <i class="fa-solid fa-xmark text-xs"></i>
      </button>
    </div>
    <form id="formAddNote" class="space-y-3">
      <input type="hidden" name="crm_action" value="add_note">
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Note *</label>
        <textarea name="body" required rows="4" class="crm-input resize-none" placeholder="Write anything about a deal, client, or idea..."></textarea>
      </div>
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Link to Client (optional)</label>
        <select name="client_id" class="crm-input">
          <option value="">-- None --</option>
          <?php foreach ($clients as $c):?>
          <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach;?>
        </select>
      </div>
      <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="pinned" value="1" class="accent-white">
        <span class="text-sm text-slate-400">Pin this note</span>
      </label>
      <button type="submit" class="w-full bg-white hover:bg-slate-200 text-black py-3 rounded-xl font-bold text-sm transition">Save Note</button>
    </form>
  </div>
</div>
<?php endif;?>


<script>
const CRM_CSRF = <?= json_encode($csrf) ?>;

function switchTab(id, btn) {
  document.querySelectorAll('.crm-tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.crm-tab').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}

function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function openAddClient() { openModal('modalAddClient'); }
function openAddTask()   { openModal('modalAddTask'); }
<?php if($is_ent):?>function openAddNote() { openModal('modalAddNote'); }<?php endif;?>

// Close modals on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(function(m) {
  m.addEventListener('click', function(e) { if (e.target === m) closeModal(m.id); });
});

async function crmPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k, v));
  const r = await fetch(location.href, { method:'POST', body:fd });
  return r.json();
}

// Add client
document.getElementById('formAddClient').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('addClientBtnLabel');
  const err = document.getElementById('addClientError');
  btn.textContent = 'Adding...';
  err.classList.add('hidden');
  const fd = new FormData(this);
  const r  = await fetch(location.href, { method:'POST', body:fd });
  const j  = await r.json();
  if (j.ok) { location.reload(); }
  else { err.textContent = j.error; err.classList.remove('hidden'); btn.textContent = 'Add Client'; }
});

// Add task
document.getElementById('formAddTask').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const j  = await (await fetch(location.href,{method:'POST',body:fd})).json();
  if (j.ok) location.reload();
});

<?php if($is_ent):?>
// Add note
document.getElementById('formAddNote').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const j  = await (await fetch(location.href,{method:'POST',body:fd})).json();
  if (j.ok) location.reload();
});

function deleteNote(id) {
  if (!confirm('Delete this note?')) return;
  crmPost({crm_action:'delete_note',note_id:id,csrf_token:CRM_CSRF}).then(j=>{
    if(j.ok) document.getElementById('note-'+id)?.remove();
  });
}

async function exportCSV() {
  const j = await crmPost({crm_action:'export_csv',csrf_token:CRM_CSRF});
  if (!j.ok) { alert(j.error); return; }
  const blob = new Blob([j.csv],{type:'text/csv'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'utiligo_clients_'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
}
<?php endif;?>

function updateStage(id, stage) {
  crmPost({crm_action:'update_stage',client_id:id,stage,csrf_token:CRM_CSRF});
}

function deleteClient(id) {
  if (!confirm('Delete this client and all their data?')) return;
  crmPost({crm_action:'delete_client',client_id:id,csrf_token:CRM_CSRF}).then(j=>{
    if(j.ok) location.reload();
  });
}

function toggleTask(id, el) {
  crmPost({crm_action:'toggle_task',task_id:id,csrf_token:CRM_CSRF}).then(j=>{
    if(!j.ok) return;
    el.classList.toggle('done');
    const row  = document.getElementById('task-'+id);
    const text = row?.querySelector('p');
    if (text) { text.classList.toggle('line-through'); text.classList.toggle('text-slate-600'); text.classList.toggle('text-white'); }
    if (el.classList.contains('done')) el.innerHTML = '<i class="fa-solid fa-check text-white text-[9px]"></i>';
    else el.innerHTML = '';
  });
}

function deleteTask(id) {
  if (!confirm('Delete this task?')) return;
  crmPost({crm_action:'delete_task',task_id:id,csrf_token:CRM_CSRF}).then(j=>{
    if(j.ok) document.getElementById('task-'+id)?.remove();
  });
}

function filterClients() {
  const q  = document.getElementById('clientSearch').value.toLowerCase();
  const st = document.getElementById('stageFilter').value;
  document.querySelectorAll('.client-row').forEach(function(row) {
    const name  = row.dataset.name  || '';
    const biz   = row.dataset.biz   || '';
    const stage = row.dataset.stage || '';
    const match = (!q || name.includes(q) || biz.includes(q)) && (!st || stage === st);
    row.style.display = match ? '' : 'none';
  });
}
</script>

<?php
// close the portal_layout main wrapper
echo '</div></main>';
echo '<?php require_once __DIR__ . \'/../includes/portal_layout_footer.php\'; ?>';
?>
