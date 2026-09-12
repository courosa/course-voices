<?php
require __DIR__ . '/lib.php';
session_start_safe();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$error = ''; $message = ''; $id = (string)($_GET['course'] ?? '');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        check_csrf(); $action=(string)($_POST['action']??'');
        if ($action==='login') {
            $access=read_data('access');
            if(empty($access['hash'])) throw new RuntimeException('Instructor access has not been configured on this server.');
            $client=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
            $allowed=locked(function()use($client){$r=read_data('attempts');$r=array_filter($r,fn($v)=>$v['until']>time());$a=$r[$client]??['count'=>0,'until'=>time()+900];if($a['count']>=10)return false;$a['count']++;$r[$client]=$a;write_data('attempts',$r);return true;});
            if(!$allowed)throw new RuntimeException('Too many attempts. Please wait 15 minutes before trying again.');
            if(!password_verify((string)($_POST['key']??''),$access['hash'])) throw new RuntimeException('That access key did not match.');
            session_regenerate_id(true);$_SESSION['admin_until']=time()+8*3600;
            locked(function()use($client){$r=read_data('attempts');unset($r[$client]);write_data('attempts',$r);});
            header('Location: admin.php');exit;
        }
        if(!admin_ok()) throw new RuntimeException('Please unlock the instructor space again.');
        if($action==='logout'){$_SESSION=[];session_destroy();header('Location: admin.php');exit;}
        if($action==='save') {
            $id=(string)($_POST['id']??'');
            $isNew=($_POST['new']??'')==='1';
            if($isNew){$slug=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',($_POST['code']??'').'-'.($_POST['term']??'')),'-'));$id=$slug.'-'.substr(bin2hex(random_bytes(3)),0,6);}
            if(!preg_match('/^[a-zA-Z0-9-]{1,90}$/',$id))throw new RuntimeException('Invalid course identifier.');
            $old=$isNew?['contributors'=>[]]:course($id);
            $c=['id'=>$id];
            foreach(['code'=>60,'term'=>60,'title'=>150,'description'=>600,'start'=>10,'end'=>10] as $field=>$max){$c[$field]=trim((string)($_POST[$field]??''));if(mb_strlen($c[$field])>$max)throw new RuntimeException('The '.$field.' field is too long.');}
            foreach(['code','term','title'] as $field)if(!$c[$field])throw new RuntimeException('Please fill in the course code, semester, and page title.');
            foreach(['start','end'] as $field)if($c[$field] && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$c[$field])||date('Y-m-d',strtotime($c[$field]))!==$c[$field]))throw new RuntimeException('Use a valid date.');
            if($c['start'] && $c['end'] && $c['start']>$c['end'])throw new RuntimeException('The end date must follow the start date.');
            $text=(string)($_POST['blogs']??'');
            if(strlen($text)>200000)throw new RuntimeException('The blog list is too large.');
            $c['contributors']=import_blogs($text,$old['contributors']);
            locked(function()use($c){$d=read_data('courses');$found=false;foreach($d['courses'] as &$entry){if($entry['id']===$c['id']){$entry=$c;$found=true;break;}}unset($entry);if(!$found)$d['courses'][]=$c;if(isset($_POST['active']))$d['active']=$c['id'];write_data('courses',$d);});
            // Force the next check to include newly added feeds; preserve collected posts.
            $feedLock=fopen(DATA_DIR.'/refresh-'.$id.'.lock','c');flock($feedLock,LOCK_EX);
            try{$cache=read_data('feed-'.$id);$cache['attempted']=0;write_data('feed-'.$id,$cache);}finally{flock($feedLock,LOCK_UN);fclose($feedLock);}
            header('Location: admin.php?course='.rawurlencode($id).'&saved=1');exit;
        }
    } catch(Throwable $e) {$error=$e->getMessage();}
}
$data=read_data('courses');$new=isset($_GET['new']) || (isset($_POST['new']) && $_POST['new']==='1');
try{$c=$new?['id'=>'','code'=>'','term'=>'','title'=>'','description'=>'Ideas, reflections, and discoveries from across our learning community.','start'=>'','end'=>'','contributors'=>[]]:course($id);}catch(Throwable $e){$c=course();}
$cache=read_data('feed-'.($c['id']?:'none'));
$blogText='';foreach($c['contributors'] as $p){$stream=fopen('php://temp','r+');fputcsv($stream,[$p['name'],$p['url'],$p['feed']],',','"','');rewind($stream);$blogText.=stream_get_contents($stream);fclose($stream);}
if($error && ($_POST['action']??'')==='save'){foreach(['code','term','title','description','start','end'] as $f)$c[$f]=(string)($_POST[$f]??'');$blogText=(string)($_POST['blogs']??'');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Instructor space · Course Voices</title><link rel="stylesheet" href="assets/style.css?v=20260912-textcards"><script src="assets/admin.js" defer></script></head><body><div class="admin-shell"><header class="topbar"><a class="brand" href="./"><span class="brand-icon">v.</span> course voices</a><a class="text-link" href="./">Back to the notebook ↗</a></header><div class="admin-intro"><p class="eyebrow">BEHIND THE CONVERSATION</p><h1>Instructor space<span class="footer-dot">.</span></h1><p>A fresh semester, a list of blogs, a place to learn together.</p></div>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(!admin_ok()):?><section class="admin-panel login-panel"><h2>Welcome back.</h2><p>Use the shared instructor access key to manage your courses. Readers never need an account.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="login"><label class="field">Instructor access key<input name="key" type="password" required autocomplete="current-password"></label><button class="primary">Open instructor space ↗</button></form><?php if(!read_data('access')):?><p class="hint">One-time hosting setup is still needed before this space can be unlocked. See the included installation guide.</p><?php endif;?></section>
<?php else:?>
<div class="admin-actions"><nav class="admin-tabs" aria-label="Courses"><?php foreach($data['courses'] as $entry):?><a href="?course=<?=h($entry['id'])?>" class="<?=!$new&&$c['id']===$entry['id']?'active':''?>"><?=h($entry['code'].' · '.$entry['term'])?></a><?php endforeach;?><a href="?new=1" class="<?=$new?'active':''?>">+ New semester</a></nav><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button class="secondary">Lock space</button></form></div>
<?php if(isset($_GET['saved'])):?><p class="success" role="status">Course saved. Check the feeds below to collect posts from the updated blog list.</p><?php endif;?>
<form method="post" id="course-form"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($c['id'])?>"><input type="hidden" name="new" value="<?=$new?'1':'0'?>">
<section class="admin-panel"><p class="eyebrow">01 / THE CLASSROOM</p><h2><?=$new?'Start a new chapter.':'Make it your course.'?></h2><p>These details appear at the top of the reading page.</p><div class="form-grid">
<label class="field">Course code<input name="code" maxlength="60" placeholder="EC&I 830" value="<?=h($c['code'])?>" required></label><label class="field">Semester<input name="term" maxlength="60" placeholder="Fall 2026" value="<?=h($c['term'])?>" required></label>
<label class="field full">Page title<input name="title" maxlength="150" placeholder="Voices of EC&I 830" value="<?=h($c['title'])?>" required></label><label class="field full">Welcome message<textarea name="description" maxlength="600"><?=h($c['description'])?></textarea></label>
<label class="field">First post date <span class="hint">(optional)</span><input name="start" type="date" value="<?=h($c['start'])?>"></label><label class="field">Last post date <span class="hint">(optional)</span><input name="end" type="date" value="<?=h($c['end'])?>"></label><p class="hint full">Leave dates empty to show all collected posts. Set a date range to keep a semester focused on its own work. Feeds may only provide their most recent posts; this site preserves posts once collected. Dates are compared in UTC.</p></div></section>
<section class="admin-panel"><p class="eyebrow">02 / THE PEOPLE</p><h2>Bring the voices together.</h2><p>Paste rows from a spreadsheet or import a CSV. Use <strong>Name, Blog URL, Feed URL</strong> columns. The feed column is optional; you can also paste one blog URL per line. For a course category, use its category feed.</p><div class="admin-actions"><label class="secondary">Import CSV<input type="file" id="csv" accept=".csv,.tsv,text/csv,text/tab-separated-values" class="sr-only"></label><a class="text-link" href="?course=<?=h($c['id'])?>&export=1" id="export-blogs">Export this list ↓</a></div><label class="field">Contributors<textarea name="blogs" id="blogs" rows="12" spellcheck="false" placeholder="Name,https://example.com/blog,https://example.com/blog/feed/"><?=h($blogText)?></textarea><small>This is the full list for this semester. Remove a row to remove that contributor from the course page. Other semesters are unchanged.</small></label><p class="import-preview" id="import-info" role="status" hidden></p></section>
<div class="admin-actions"><label class="check-label"><input type="checkbox" name="active" <?=($new||$data['active']===$c['id'])?'checked':''?>> Make this the course shown on the homepage</label><button class="primary">Save course ↗</button></div></form>
<?php if(!$new):?><section class="admin-panel"><div class="admin-actions"><div><p class="eyebrow">03 / THE CONNECTIONS</p><h2>Keep the conversation flowing.</h2></div><button class="secondary" id="refresh-feeds" data-course="<?=h($c['id'])?>">Check feeds now ↻</button></div><p>Blogs are checked every 15 minutes when the page is visited. A hosting schedule can check them even when nobody is reading.</p><p id="refresh-result" role="status" class="hint"><?=isset($cache['checked'])?'Last check: '.h($cache['checked']):'No feed check yet.'?></p><table class="feed-status"><thead><tr><th>Contributor</th><th>Feed status</th></tr></thead><tbody><?php foreach($c['contributors'] as $p):$s=$cache['statuses'][$p['id']]??null;?><tr><td><?=h($p['name'])?><small><?=h($p['url'])?></small></td><td class="<?=$s?($s['ok']?'status-ok':'status-error'):''?>"><?=$s?($s['ok']?'Connected · '.$s['count'].' recent posts':h($s['message'])):'Waiting for first check'?></td></tr><?php endforeach;?></tbody></table></section><?php endif;?>
<?php endif;?></div></body></html>
