<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$page=(string)($_GET['page']??'dashboard');
$error='';
$notice=$_SESSION['flash']??'';
unset($_SESSION['flash']);
function repairs(PDO $db,array $u):array{
    $sql='SELECT r.*,d.device_type,d.brand_model,d.device_code,c.name customer_name,t.name technician_name, (SELECT ru.note FROM repair_updates ru WHERE ru.repair_id=r.id ORDER BY ru.created_at DESC,ru.id DESC LIMIT 1) latest_update_note, (SELECT ru.created_at FROM repair_updates ru WHERE ru.repair_id=r.id ORDER BY ru.created_at DESC,ru.id DESC LIMIT 1) latest_update_at FROM repair_requests r JOIN devices d ON d.id=r.device_id JOIN users c ON c.id=r.customer_id LEFT JOIN users t ON t.id=r.technician_id';
    if($u['role']==='customer'){
        $q=$db->prepare($sql.' WHERE r.customer_id=? ORDER BY r.created_at DESC');
        $q->execute([$u['id']]);
        return $q->fetchAll();
    }
    if($u['role']==='technician'){
        $q=$db->prepare($sql.' WHERE r.technician_id=? ORDER BY r.created_at DESC');
        $q->execute([$u['id']]);
        return $q->fetchAll();
    }
    return $db->query($sql.' ORDER BY r.created_at DESC')->fetchAll();
}
function badge(string $s):string{
    return '<span class="badge '.strtolower(str_replace(' ','-',e($s))).'">'.e($s).'</span>';
}
function tab_script():string{
    return "(()=>{const init=()=>{let p=new URLSearchParams(location.search),t=p.get('tab');if(!/^[a-f0-9]{32}$/.test(t||'')){t=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');p.set('tab',t);history.replaceState(null,'',location.pathname+'?'+p.toString())}document.querySelectorAll('a[href]').forEach(a=>{try{let u=new URL(a.href,location.href);if(u.origin===location.origin&&/\\.php$/i.test(u.pathname)){u.searchParams.set('tab',t);a.href=u.href}}catch(e){}});document.querySelectorAll('form').forEach(f=>{let i=f.querySelector('input[name=tab]');if(!i){i=document.createElement('input');i.type='hidden';i.name='tab';f.append(i)}i.value=t})};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init()})();";
}
function shell_start(string $title):void{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' · Re:pair</title><link rel="stylesheet" href="assets/style.css"></head><body><script>'.tab_script().'</script>';
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='register'){
            $name=trim((string)($_POST['name']??''));
            $email=strtolower(trim((string)($_POST['email']??'')));
            $pw=(string)($_POST['password']??'');
            if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pw)<10)throw new RuntimeException('Enter your name, a valid email and a password of at least 10 characters.');
            $q=$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');
            $q->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user']=['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'email'=>$email,'role'=>'customer'];
            go('dashboard');
        }
        if($action==='login'){
            $q=$pdo->prepare('SELECT * FROM users WHERE email=?');
            $q->execute([strtolower(trim((string)($_POST['email']??'')))]);
            $a=$q->fetch();
            if(!$a||!$a['active']||!password_verify((string)($_POST['password']??''),$a['password_hash']))throw new RuntimeException('Email or password is incorrect, or the account is inactive.');
            session_regenerate_id(true);
            $_SESSION['user']=['id'=>(int)$a['id'],'name'=>$a['name'],'email'=>$a['email'],'role'=>$a['role']];
            go('dashboard');
        }
        if($action==='logout'){
            $_SESSION=[];
            session_destroy();
            go('login');
        }
        require_login();
        if($action==='create'){
            require_role(['customer']);
            $type=trim((string)($_POST['device_type']??''));
            $model=trim((string)($_POST['brand_model']??''));
            $issue=trim((string)($_POST['issue_description']??''));
            $diagnosisFirst=!empty($_POST['diagnosis_first']);
            if(!$issue&&!$diagnosisFirst)throw new RuntimeException('Describe the issue, or select diagnosis/check-up first.');
            if(!$issue)$issue='Customer requests a diagnosis/check-up before deciding whether to repair.';
            $pdo->beginTransaction();
            $dev=(int)($_POST['device_id']??0);
            $devicePrice=max(0,(float)($_POST['device_price']??0));
            if($dev){
                $q=$pdo->prepare('SELECT id FROM devices WHERE id=? AND customer_id=?');
                $q->execute([$dev,current_user()['id']]);
                if(!$q->fetch())throw new RuntimeException('Choose one of your own registered devices.');
                if($devicePrice>0){
                    $q=$pdo->prepare('UPDATE devices SET device_price=? WHERE id=?');
                    $q->execute([$devicePrice,$dev]);
                }
            } else{
                if(!$type||!$model||$devicePrice<=0)throw new RuntimeException('For a new device, enter its type, brand/model and original price.');
                $q=$pdo->prepare('INSERT INTO devices(customer_id,device_type,brand_model,serial_number,device_price) VALUES(?,?,?,?,?)');
                $q->execute([current_user()['id'],$type,$model,trim((string)($_POST['serial_number']??''))?:null,$devicePrice]);
                $dev=(int)$pdo->lastInsertId();
                $code='D-'.str_pad((string)$dev,4,'0',STR_PAD_LEFT);
                $q=$pdo->prepare('UPDATE devices SET device_code=? WHERE id=?');
                $q->execute([$code,$dev]);
            }
            $photo=upload_photo($_FILES['photo']??null);
            $q=$pdo->prepare('INSERT INTO repair_requests(ticket_no,customer_id,device_id,issue_description,photo_path,diagnosis_first,customer_decision) VALUES(?,?,?,?,?,?,?)');
            $q->execute([ticket_number(),current_user()['id'],$dev,$issue,$photo,$diagnosisFirst?1:0,$diagnosisFirst?'Pending':'Proceed']);
            $id=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('INSERT INTO repair_updates(repair_id,actor_id,status,note) VALUES(?,?,?,?)');
            $q->execute([$id,current_user()['id'],'Submitted','Repair request submitted by customer.']);
            $pdo->commit();
            $_SESSION['flash']='Repair request submitted.';
            go('repairs');
        }
        if($action==='update'){
            require_role(['technician','admin']);
            $id=(int)$_POST['repair_id'];
            $q=$pdo->prepare('SELECT r.*,u.name customer_name,d.brand_model,d.device_code FROM repair_requests r JOIN users u ON u.id=r.customer_id JOIN devices d ON d.id=r.device_id WHERE r.id=?');
            $q->execute([$id]);
            $r=$q->fetch();
            if(!$r)throw new RuntimeException('Repair request not found.');
            if(current_user()['role']==='technician'&&(int)$r['technician_id']!==(int)current_user()['id'])throw new RuntimeException('This repair is not assigned to your account.');
            $status=(string)$_POST['status'];
            $states=['Assigned','In progress','Completed'];
            if(!in_array($status,$states,true))throw new RuntimeException('Invalid status.');
            $diag=trim((string)$_POST['diagnosis']);
            $parts=trim((string)$_POST['parts_used']);
            $cost=max(0,(float)$_POST['estimated_cost']);
$finalCostInput=trim((string)($_POST['final_cost']??''));
if($status==='Completed'&&$finalCostInput==='')throw new RuntimeException('Enter the final repair cost before completing this repair.');
$finalCost=$finalCostInput===''?($r['final_cost']===null?null:(float)$r['final_cost']):max(0,(float)$finalCostInput);
            $warranty=trim((string)$_POST['warranty']);
            $opinion=(string)($_POST['technician_opinion']??'Undecided');
            if(!in_array($opinion,['Repair','Replace','Undecided'],true))throw new RuntimeException('Choose a valid technician opinion.');
            if($r['status']==='Cancelled')throw new RuntimeException('This repair was cancelled and cannot be updated.');
if($r['customer_decision']==='Declined')throw new RuntimeException('The customer cancelled this repair. No further updates are allowed.');
if((int)$r['diagnosis_first']===1&&$r['customer_decision']!=='Proceed'&&!in_array($status,['Submitted','Assigned','Diagnosis'],true))throw new RuntimeException('Wait for the customer to proceed before updating repair progress.');
            if((int)$r['diagnosis_first']&&$r['customer_decision']==='Pending'&&$status==='Assigned'&&(!$diag||trim((string)($_POST['estimated_cost']??''))===''||$opinion==='Undecided'))throw new RuntimeException('Enter the diagnosis, estimated cost, and recommendation before sending it to the customer.');
            $note=trim((string)$_POST['note'])?:($diag?:'Status updated to '.$status.'.');
            $pdo->beginTransaction();
            $q=$pdo->prepare('UPDATE repair_requests SET status=?,diagnosis=?,estimated_cost=?,final_cost=?,warranty=?,technician_opinion=? WHERE id=?');
            $q->execute([$status,$diag?:null,$cost,$finalCost,$warranty?:null,$opinion,$id]);
            $q=$pdo->prepare('INSERT INTO repair_updates(repair_id,actor_id,status,note,parts_used) VALUES(?,?,?,?,?)');
            $q->execute([$id,current_user()['id'],$status,$note,$parts?:null]);
            $diagnosisReady=(int)$r['diagnosis_first']&&$r['customer_decision']==='Pending'&&$status==='Assigned'&&(!in_array($r['status'],['Assigned','Diagnosis'],true)||$diag!==(string)($r['diagnosis']??'')||$cost!==(float)$r['estimated_cost']||$opinion!==$r['technician_opinion']);
            if($diagnosisReady){
                $message='Diagnosis ready. Estimate: RM '.number_format($cost,2).'. Technician opinion: '.$opinion.'. Review the repair details and choose whether to proceed.';
                $q=$pdo->prepare('INSERT INTO customer_notifications(customer_id,repair_id,status,message) VALUES(?,?,?,?)');
                $q->execute([$r['customer_id'],$id,'Diagnosis ready',$message]);
            } elseif($status!==$r['status']){
                $message='Your '.$r['brand_model'].' repair status is now '.$status.'.';
                $q=$pdo->prepare('INSERT INTO customer_notifications(customer_id,repair_id,status,message) VALUES(?,?,?,?)');
                $q->execute([$r['customer_id'],$id,$status,$message]);
            }
            $pdo->commit();
            $_SESSION['flash']=$status!==$r['status']?'Repair record updated. The customer has a new notification.':'Repair record updated.';
            go('repair&id='.$id);
        }
        if($action==='customer_decision'){
            require_role(['customer']);
            $id=(int)($_POST['repair_id']??0);
            $decision=(string)($_POST['decision']??'');
            if(!in_array($decision,['Proceed','Declined'],true))throw new RuntimeException('Choose whether to proceed with the repair.');
            $q=$pdo->prepare('SELECT * FROM repair_requests WHERE id=? AND customer_id=?');
            $q->execute([$id,current_user()['id']]);
            $r=$q->fetch();
            if(!$r||!(((int)$r['diagnosis_first']===1&&$r['customer_decision']==='Pending')||((int)$r['diagnosis_first']===0&&in_array($r['status'],['Assigned','Diagnosis'],true))))throw new RuntimeException('This repair is not waiting for your decision.');
            if(!in_array($r['status'],['Assigned','Diagnosis'],true)||!trim((string)$r['diagnosis']))throw new RuntimeException('Wait for the technician to provide the diagnosis first.');
            $nextStatus=$decision==='Proceed'?'In progress':'Cancelled';
            $note=$decision==='Proceed'?'Customer approved the repair estimate and chose to proceed.':'Customer declined the repair after reviewing the diagnosis.';
            $pdo->beginTransaction();
            $q=$pdo->prepare('UPDATE repair_requests SET customer_decision=?,status=? WHERE id=?');
            $q->execute([$decision,$nextStatus,$id]);
            $q=$pdo->prepare('INSERT INTO repair_updates(repair_id,actor_id,status,note) VALUES(?,?,?,?)');
            $q->execute([$id,current_user()['id'],$decision==='Proceed'?'Customer approved':'Customer declined',$note]);
            $pdo->commit();
            $_SESSION['flash']=$decision==='Proceed'?'You approved the repair. The technician can continue.':'You declined the repair. No repair work will continue.';
            go('repair&id='.$id);
        }
        if($action==='assign'){
            require_role(['admin']);
            $id=(int)$_POST['repair_id'];
            $tech=(int)$_POST['technician_id'];
            if($tech){
                $v=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='technician' AND active=1");
                $v->execute([$tech]);
                if(!$v->fetch())throw new RuntimeException('Choose an active technician.');
            }
            $q=$pdo->prepare("UPDATE repair_requests SET technician_id=?,status=CASE WHEN ? IS NOT NULL AND status='Submitted' THEN 'Assigned' ELSE status END WHERE id=?");
            $q->execute([$tech?:null,$tech?:null,$id]);
            $q=$pdo->prepare('INSERT INTO repair_updates(repair_id,actor_id,status,note) SELECT id,?,?,? FROM repair_requests WHERE id=?');
            $q->execute([current_user()['id'],'Assignment','Technician assignment updated.',$id]);
            $_SESSION['flash']='Technician assignment saved.';
            go('repair&id='.$id);
        }
        if($action==='user_role'){
            require_role(['admin']);
            $id=(int)$_POST['user_id'];
            $role=(string)$_POST['role'];
            $active=isset($_POST['active'])?1:0;
            if(!in_array($role,['customer','technician','admin'],true))throw new RuntimeException('Invalid user role.');
            if($id===(int)current_user()['id']&&($role!=='admin'||!$active))throw new RuntimeException('You cannot deactivate or remove your own administrator role.');
            $q=$pdo->prepare('UPDATE users SET role=?,active=? WHERE id=?');
            $q->execute([$role,$active,$id]);
            $_SESSION['flash']='Account updated.';
            go('users');
        }
        if($action==='recommend_replacement'){
            require_role(['technician']);
            $deviceId=(int)$_POST['device_id'];
            $q=$pdo->prepare('SELECT d.*,c.name customer_name FROM devices d JOIN users c ON c.id=d.customer_id WHERE d.id=?');
            $q->execute([$deviceId]);
            $device=$q->fetch();
            if(!$device)throw new RuntimeException('Device not found.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM repair_requests WHERE device_id=? AND technician_id=?');
            $q->execute([$deviceId,current_user()['id']]);
            if(!(int)$q->fetchColumn())throw new RuntimeException('You can only advise on a device assigned to you.');
            $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='Cancelled' THEN 0 WHEN status='Completed' THEN COALESCE(final_cost,estimated_cost) ELSE estimated_cost END),0) FROM repair_requests WHERE device_id=?");
            $q->execute([$deviceId]);
            $sum=(float)$q->fetchColumn();
            if((float)$device['device_price']<=0||$sum<=(float)$device['device_price']*.5)throw new RuntimeException('The recorded repair estimates must exceed half the device price first.');
            $pdo->beginTransaction();
            $q=$pdo->prepare('UPDATE devices SET replacement_advice_by=?,replacement_advice_at=NOW() WHERE id=?');
            $q->execute([current_user()['id'],$deviceId]);
            $q=$pdo->prepare("SELECT id FROM repair_requests WHERE device_id=? AND status<>'Cancelled' ORDER BY created_at DESC LIMIT 1");
            $q->execute([$deviceId]);
            $repairId=(int)$q->fetchColumn();
            $message='Technician recommendation: consider replacing your '.$device['brand_model'].'. The recorded repair estimates exceed half of its price. The decision is yours.';
            $q=$pdo->prepare('INSERT INTO customer_notifications(customer_id,repair_id,status,message) VALUES(?,?,?,?)');
            $q->execute([$device['customer_id'],$repairId,'Technician recommendation',$message]);
            $pdo->commit();
            $_SESSION['flash']='Your recommendation was sent to the customer.';
            go('passport&code='.rawurlencode((string)$device['device_code']));
        }
        throw new RuntimeException('Unknown action.');
    } catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e instanceof PDOException&&$e->getCode()==='23000'?'That email address is already registered.':$e->getMessage();
    }
}
if($page==='photo'&&current_user()){
    $id=(int)($_GET['id']??0);
    $q=$pdo->prepare('SELECT r.photo_path,r.customer_id,r.technician_id FROM repair_requests r WHERE r.id=?');
    $q->execute([$id]);
    $photo=$q->fetch();
    if(!$photo||!$photo['photo_path']||!can_view_repair($photo)){
        http_response_code(404);
        exit;
    }
    $path=__DIR__.'/uploads/'.basename($photo['photo_path']);
    if(!is_file($path)){
        http_response_code(404);
        exit;
    }
    header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
$u=current_user();
$notificationCount=0;
if($u&&$u['role']==='customer'){
    $count=$pdo->prepare('SELECT COUNT(*) FROM customer_notifications WHERE customer_id=? AND read_at IS NULL');
    $count->execute([$u['id']]);
    $notificationCount=(int)$count->fetchColumn();
    if($page==='notifications'){
        $mark=$pdo->prepare('UPDATE customer_notifications SET read_at=NOW() WHERE customer_id=? AND read_at IS NULL');
        $mark->execute([$u['id']]);
        $notificationCount=0;
    }
}
if(!$u){
    if(!in_array($page,['login','register'],true))$page='login';
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($page==='register'?'Create account':'Sign in')?> · Re:pair</title><link rel="stylesheet" href="assets/style.css"></head><body><script><?=tab_script()?></script><main class="auth-wrap"><a class="brand" href="index.php"><span class="brand-icon">↗</span>re<span class="brand-green">:pair</span></a><section class="auth-card"><p class="eyebrow">REPAIR CENTER</p><h1><?=$page==='register'?'Create your account':'Welcome back'?></h1><p class="muted"><?=$page==='register'?'Track repairs and keep a passport for each device.':'Sign in to your repair workspace.'?></p><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;
    ?><?php if($page==='register'):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="register"><label>Full name<input name="name" required maxlength="120"></label><label>Email<input name="email" type="email" required></label><label>Password<input name="password" type="password" required minlength="10"></label><button class="button full">Create account</button></form><p class="auth-switch">Already registered? <a href="index.php?page=login">Sign in</a></p><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="login"><label>Email<input name="email" type="email" required autofocus></label><label>Password<input name="password" type="password" required></label><button class="button full">Sign in</button></form><p class="auth-switch">New here? <a href="index.php?page=register">Create an account</a></p><?php endif;
    ?></section></main></body></html><?php exit;
}
$all=repairs($pdo,$u);
$names=['dashboard'=>'Overview','repairs'=>'Repair requests','new'=>'New repair request','passport'=>'Device passport','compare'=>'Repair comparison','users'=>'User accounts','reports'=>'Reports','repair'=>'Repair details','notifications'=>'Notifications','help'=>'Help guide'];
$valid=['dashboard','repairs','passport','compare','repair','help'];
if($u['role']==='customer')$valid=array_merge($valid,['new','notifications']);
if($u['role']==='admin')$valid=array_merge($valid,['users','reports']);
if(!in_array($page,$valid,true))$page='dashboard';
shell_start($names[$page]);
$dashboardRoute=$u['role']==='admin'?'admin_dashboard.php':($u['role']==='technician'?'technician_dashboard.php':'customer_dashboard.php');
$repairRoute=$u['role']==='customer'?'my_repairs.php':'manage_repairs.php';
$routes=['dashboard'=>$dashboardRoute,'repairs'=>$repairRoute,'passport'=>'device_passport.php','compare'=>'repair_comparison.php','notifications'=>'customer_notifications.php','users'=>'manage_users.php','reports'=>'generate_reports.php','help'=>'index.php?page=help'];
$nav=[['dashboard','⌂','Overview'],['repairs','▤',$u['role']==='customer'?'My repairs':($u['role']==='technician'?'Repair queue':'All repairs')],['passport','◈','Device passport'],['compare','⇄','Repair comparison']];
$nav[]=['help','?','Help'];
if($u['role']==='customer')$nav[]=['notifications','●','Notifications'.($notificationCount?' ('.$notificationCount.')':'')];
if($u['role']==='admin')$nav=array_merge($nav,[['users','♙','User accounts'],['reports','▥','Reports']]);
?><div class="app"><aside class="sidebar"><a class="brand" href="index.php"><span class="brand-icon">↗</span>re<span class="brand-green">:pair</span></a><div class="workspace">WORKSPACE<b>Repair Center · Portal</b></div><p class="side-label">WORKSPACE</p><nav><?php foreach($nav as $n):?><a class="nav-link <?=$page===$n[0]?'active':''?>" href="<?=$routes[$n[0]]?>"><span><?=$n[1]?></span><?=$n[2]?></a><?php endforeach;
?></nav><div class="sidebar-bottom"><div class="user-chip"><span class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></span><div><b><?=e($u['name'])?></b><small><?=e(ucfirst($u['role']))?></small></div></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="logout"><button class="button secondary full">Sign out</button></form></div></aside><main class="main"><header class="topbar"><div><small>REPAIR CENTER</small><b><?=e($names[$page])?></b></div><div class="top-user-actions"><span class="top-user"><?=e($u['name'])?></span><form method="post" class="mobile-signout"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="logout"><button class="button secondary">Sign out</button></form></div></header><section class="content"><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;
?><?php if($notice):?><div class="alert success"><?=e($notice)?></div><?php endif;
?>
<?php
if ($page === 'dashboard') {
    require __DIR__ . '/includes/pages/dashboard.php';
} elseif ($page === 'repairs') {
    require __DIR__ . '/includes/pages/repairs.php';
} elseif ($page === 'new') {
    require __DIR__ . '/includes/pages/new.php';
} elseif ($page === 'repair') {
    require __DIR__ . '/includes/pages/repair.php';
} elseif ($page === 'passport') {
    require __DIR__ . '/includes/pages/passport.php';
} elseif ($page === 'compare') {
    require __DIR__ . '/includes/pages/compare.php';
} elseif ($page === 'notifications' && $u['role'] === 'customer') {
    require __DIR__ . '/includes/pages/notifications.php';
} elseif ($page === 'users' && $u['role'] === 'admin') {
    require __DIR__ . '/includes/pages/users.php';
} elseif ($page === 'reports' && $u['role'] === 'admin') {
    require __DIR__ . '/includes/pages/reports.php';
} elseif ($page === 'help') {
    require __DIR__ . '/includes/pages/help.php';
}

?></section></main></div></body></html>
